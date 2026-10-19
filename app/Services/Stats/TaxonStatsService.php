<?php

namespace App\Services\Stats;

use Config\TaxonFrequencyTrend;
use Config\TaxonStats;
use Config\TaxonYearStats;
use RuntimeException;

/**
 * Recomputes taxon_stats derived aggregates from active occurrences.
 */
class TaxonStatsService
{
    /**
     * Create the taxon statistics service.
     *
     * @param StatsDirtyScopeService|null $dirtyScopeService Dirty queue service.
     * @param TaxonStats|null             $config Taxon statistics runtime limits.
     */
    public function __construct(
        private readonly ?StatsDirtyScopeService $dirtyScopeService = null,
        private readonly ?TaxonStats $config = null,
    ) {
    }

    /**
     * Recompute taxon stats for global and regional scopes.
     *
     * @param bool $dryRun Whether persistence is disabled for this run.
     *
     * @return array<string, int|string|bool>
     */
    public function run(bool $dryRun = false): array
    {
        $db = db_connect();
        $offsetModel = model(\App\Models\ImportOffsetModel::class);
        $hasRows = $db->table('taxon_stats')->countAllResults() > 0;
        $isComplete = $offsetModel->isComplete('derived-stats:taxon_stats');
        $hasDirtyScopes = $this->dirtyScopeService !== null
            && $this->dirtyScopeService->hasDirtyScopes(StatsDirtyScopeService::TAXON);

        if ($hasRows && $isComplete) {
            if ($hasDirtyScopes) {
                return $this->runIncremental($dryRun);
            }

            return [
                'status' => 'success',
                'fetched' => 0,
                'processed' => 0,
                'inserted' => 0,
                'updated' => 0,
                'not changed' => 0,
                'skipped' => 0,
                'errors' => 0,
                'has_more' => false,
            ];
        }

        return $this->runFullRebuild($dryRun, $offsetModel);
    }

    /**
     * Populate one or more resumable taxon-ID chunks without exceeding the PHP time budget.
     *
     * Older numeric checkpoints still identify the next reporting projection. New checkpoints
     * also store the last taxon ID processed within that projection.
     *
     * @param bool                            $dryRun Whether persistence is disabled.
     * @param \App\Models\ImportOffsetModel   $offsetModel Statistics checkpoint model.
     * @return array<string, int|string|bool> Batch result and continuation state.
     */
    private function runFullRebuild(bool $dryRun, \App\Models\ImportOffsetModel $offsetModel): array
    {
        $counts = [
            'status' => 'success',
            'fetched' => 0,
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'not changed' => 0,
            'skipped' => 0,
            'errors' => 0,
            'has_more' => false,
        ];

        try {
            $config = $this->config ?? config(TaxonStats::class);
            $batches = $this->statBatches();
            [$batchIndex, $afterTaxonId] = $this->parseRebuildCheckpoint(
                $offsetModel->getCheckpoint('derived-stats:taxon_stats'),
                count($batches),
            );
            $startedAt = microtime(true);
            $runtimeBudget = $this->runtimeBudget($config->maxRuntimeSeconds);
            $taxaProcessed = 0;
            $frequencyTrends = null;

            while ($batchIndex < count($batches)) {
                if ($taxaProcessed >= $config->maxTaxaPerRun
                    || ($taxaProcessed > 0 && microtime(true) - $startedAt >= $runtimeBudget)) {
                    break;
                }

                $batch = $batches[$batchIndex];
                [$taxonIds, $hasMoreTaxa] = $this->nextTaxonIds(
                    $batch,
                    $afterTaxonId,
                    min($config->taxaPerBatch, $config->maxTaxaPerRun - $taxaProcessed),
                );

                if ($taxonIds === []) {
                    $batchIndex++;
                    $afterTaxonId = 0;
                    continue;
                }

                $rows = $this->buildRows($batch, null, $taxonIds);
                $counts['fetched'] += count($rows);
                $counts['processed'] += count($rows);
                $counts['inserted'] += count($rows);

                $projectionHasMore = $hasMoreTaxa || $batchIndex < count($batches) - 1;
                if ($dryRun) {
                    $counts['has_more'] = $projectionHasMore;

                    return $counts;
                }

                if ($rows !== [] && $frequencyTrends === null) {
                    $frequencyTrends = $this->frequencyTrends();
                }
                $this->replaceFullRebuildRows($taxonIds, $rows, $frequencyTrends);
                $taxaProcessed += count($taxonIds);

                if ($hasMoreTaxa) {
                    $afterTaxonId = (int) end($taxonIds);
                } else {
                    $batchIndex++;
                    $afterTaxonId = 0;
                }

                $hasMore = $batchIndex < count($batches);
                if ($hasMore) {
                    $offsetModel->setCheckpoint(
                        'derived-stats:taxon_stats',
                        $afterTaxonId > 0 ? $batchIndex . ':' . $afterTaxonId : (string) $batchIndex,
                    );
                    $offsetModel->setCompletion('derived-stats:taxon_stats', false);
                }

                $counts['has_more'] = true;
            }

            $hasMore = $batchIndex < count($batches);
            $counts['has_more'] = $hasMore;

            if (! $dryRun) {
                if (! $hasMore) {
                    ($this->dirtyScopeService ?? service('statsDirtyScopeService'))
                        ->clear(StatsDirtyScopeService::TAXON);
                    $offsetModel->setCheckpoint('derived-stats:taxon_stats', null);
                    $offsetModel->setCompletion('derived-stats:taxon_stats', true);
                } else {
                    $offsetModel->setCheckpoint(
                        'derived-stats:taxon_stats',
                        $afterTaxonId > 0 ? $batchIndex . ':' . $afterTaxonId : (string) $batchIndex,
                    );
                    $offsetModel->setCompletion('derived-stats:taxon_stats', false);
                }
            }
        } catch (\Throwable $exception) {
            log_message('error', $exception->getMessage());
            $counts['status'] = 'failed';
            $counts['errors']++;
        }

        return $counts;
    }

    /**
     * Reset derived statistics while retaining curated key-species decisions.
     *
     * @return void
     */
    public function resetFullRebuild(): void
    {
        $db = db_connect();
        $db->transException(true)->transBegin();
        try {
            $existingRows = $db->table('taxon_stats')
                ->select('uuid, taxon_id, geographic_region_id, key_species, key_species_comment, key_species_decision_date')
                ->groupStart()
                ->where('key_species IS NOT NULL', null, false)
                ->orWhere('key_species_comment IS NOT NULL', null, false)
                ->orWhere('key_species_decision_date IS NOT NULL', null, false)
                ->groupEnd()
                ->get()
                ->getResultArray();

            $db->table('taxon_stats')->emptyTable();
            foreach ($existingRows as $row) {
                $db->table('taxon_stats')->insert([
                    'uuid' => $row['uuid'],
                    'taxon_id' => (int) $row['taxon_id'],
                    'geographic_region_id' => $row['geographic_region_id'] === null
                        ? null
                        : (int) $row['geographic_region_id'],
                    'occurrences_count' => 0,
                    'grid_square_count' => 0,
                    'mean_squares_per_year' => 0,
                    'mean_occurrences_per_year' => 0,
                    'frequency_trend' => null,
                    'frequency_trend_state' => null,
                    'first_record_date' => null,
                    'last_record_date' => null,
                    'first_recorder' => '',
                    'last_recorder' => '',
                    'first_verified_record_date' => null,
                    'last_verified_record_date' => null,
                    'first_verified_recorder' => '',
                    'last_verified_recorder' => '',
                    'key_species' => $row['key_species'],
                    'key_species_comment' => $row['key_species_comment'],
                    'key_species_decision_date' => $row['key_species_decision_date'],
                ]);
            }

            if (! $db->transStatus() || ! $db->transCommit()) {
                throw new \RuntimeException('Taxon statistics could not be reset.');
            }
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }

        $offsetModel = model(\App\Models\ImportOffsetModel::class);
        $offsetModel->setCheckpoint('derived-stats:taxon_stats', null);
        $offsetModel->setCompletion('derived-stats:taxon_stats', false);

        ($this->dirtyScopeService ?? service('statsDirtyScopeService'))
            ->clear(StatsDirtyScopeService::TAXON);
    }

    /**
     * Process queued global and regional taxon scopes within configured limits.
     *
     * Frequency trends remain calculated by the existing full trend routine so
     * dense ranking continues to use every taxon in the affected rank/scope.
     *
     * @param bool $dryRun Whether persistence is disabled for this run.
     * @return array<string, int|string|bool> Incremental result and continuation state.
     */
    private function runIncremental(bool $dryRun): array
    {
        $counts = [
            'status' => 'success', 'fetched' => 0, 'processed' => 0, 'inserted' => 0,
            'updated' => 0, 'not changed' => 0, 'skipped' => 0, 'errors' => 0, 'has_more' => false,
        ];
        $dirty = $this->dirtyScopeService ?? service('statsDirtyScopeService');
        $config = config(TaxonYearStats::class);
        $startedAt = microtime(true);
        $processedScopeIds = [];

        try {
            $scopes = $dirty->next(StatsDirtyScopeService::TAXON, $config->maxScopes);
            $counts['fetched'] = count($scopes);
            foreach ($scopes as $scope) {
                if ($counts['processed'] > 0 && microtime(true) - $startedAt >= $config->maxRuntimeSeconds) {
                    break;
                }

                $projection = (string) ($scope['projection'] ?? '');
                $batch = $projection === 'exact'
                    ? ['columns' => [], 'include_exact' => true]
                    : ['columns' => [$projection], 'include_exact' => false];
                $rows = $this->buildRows($batch, $scope);
                $this->replaceDirtyRows($scope, $rows, $dryRun);
                if (! $dryRun) {
                    $processedScopeIds[] = (int) $scope['id'];
                }
                $counts['processed']++;
                $counts['updated']++;
                $counts['inserted'] += count($rows);
            }
            if (! $dryRun && $counts['processed'] > 0) {
                $this->refreshFrequencyTrends();
            }
            if (! $dryRun && $processedScopeIds !== []) {
                $dirty->acknowledge($processedScopeIds);
            }
            $counts['has_more'] = $dirty->hasDirtyScopes(StatsDirtyScopeService::TAXON);
        } catch (\Throwable $exception) {
            log_message('error', $exception->getMessage());
            $counts['status'] = 'failed';
            $counts['errors'] = 1;
        }

        return $counts;
    }

    /**
     * Reapply dense frequency trends after a partial aggregate update.
     *
     * @return void
     */
    private function refreshFrequencyTrends(): void
    {
        $db = db_connect();
        $trends = $this->frequencyTrends();
        $rows = $db->table('taxon_stats')->select('id, taxon_id, geographic_region_id')->get()->getResultArray();
        $updates = [];

        foreach ($rows as $row) {
            $trend = $trends[$this->scopeKey(
                (int) $row['taxon_id'],
                $this->nullableInt($row['geographic_region_id'] ?? null),
            )] ?? ['score' => null, 'state' => null];
            $updates[] = [
                'frequency_trend' => $trend['score'],
                'frequency_trend_state' => $trend['state'],
                'id' => (int) $row['id'],
            ];
        }

        foreach (array_chunk($updates, 500) as $chunk) {
            $db->table('taxon_stats')->updateBatch($chunk, 'id');
        }
    }

    /**
     * Replace a dirty taxon aggregate, deleting it when no active records remain.
     *
     * @param array<string, mixed>        $scope Dirty queue row.
     * @param array<int, array<string,mixed>> $rows Recomputed rows.
     * @param bool                         $dryRun Whether persistence is disabled.
     * @return void
     */
    private function replaceDirtyRows(array $scope, array $rows, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $db = db_connect();
        $builder = $db->table('taxon_stats')
            ->where('taxon_id', (int) $scope['taxon_id']);
        if ($scope['geographic_region_id'] === null) {
            $builder->where('geographic_region_id', null);
        } else {
            $builder->where('geographic_region_id', (int) $scope['geographic_region_id']);
        }

        $db->transException(true)->transBegin();
        try {
            $existingRows = $builder
                ->select('taxon_id, geographic_region_id, key_species, key_species_comment, key_species_decision_date')
                ->get()
                ->getResultArray();
            $rows = $this->mergeKeySpeciesFields($rows, $existingRows);

            $deleteBuilder = $db->table('taxon_stats')
                ->where('taxon_id', (int) $scope['taxon_id']);
            if ($scope['geographic_region_id'] === null) {
                $deleteBuilder->where('geographic_region_id', null);
            } else {
                $deleteBuilder->where('geographic_region_id', (int) $scope['geographic_region_id']);
            }
            $deleteBuilder->delete();
            if ($rows !== []) {
                $this->insertRows($rows, []);
            }

            if (! $db->transStatus() || ! $db->transCommit()) {
                throw new \RuntimeException('Taxon statistics scope could not be replaced.');
            }
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /**
     * Replace full-rebuild rows for a bounded set of taxon IDs.
     *
     * @param array<int, int>              $taxonIds Taxa covered by this rebuild chunk.
     * @param array<int, array<string,mixed>> $rows Computed statistics.
     * @param array<string, array{score: int|null, state: string}>|null $frequencyTrends
     *        Whole-rank trend results shared by chunks in this invocation.
     * @return void
     */
    private function replaceFullRebuildRows(
        array $taxonIds,
        array $rows,
        ?array $frequencyTrends,
    ): void
    {
        $db = db_connect();
        $db->transException(true)->transBegin();
        try {
            $existingRows = $db->table('taxon_stats')
                ->select('taxon_id, geographic_region_id, key_species, key_species_comment, key_species_decision_date')
                ->whereIn('taxon_id', $taxonIds)
                ->get()
                ->getResultArray();
            $rows = $this->mergeKeySpeciesFields($rows, $existingRows);
            $db->table('taxon_stats')->whereIn('taxon_id', $taxonIds)->delete();

            if ($rows !== []) {
                $this->insertRows($rows, $frequencyTrends);
            }

            if (! $db->transStatus() || ! $db->transCommit()) {
                throw new \RuntimeException('Taxon statistics chunk could not be replaced.');
            }
        } catch (\Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /**
     * Carry curated decisions into recomputed rows and retain decision-only scopes.
     *
     * @param array<int, array<string, mixed>> $rows Newly computed rows.
     * @param array<int, array<string, mixed>> $existingRows Previous rows with curated fields.
     * @return array<int, array<string, mixed>> Rows ready for replacement.
     */
    private function mergeKeySpeciesFields(array $rows, array $existingRows): array
    {
        $existingByScope = [];
        foreach ($existingRows as $existingRow) {
            $key = $this->scopeKey(
                (int) $existingRow['taxon_id'],
                $this->nullableInt($existingRow['geographic_region_id'] ?? null),
            );
            $existingByScope[$key] = $existingRow;
        }

        $computedScopes = [];
        foreach ($rows as &$row) {
            $key = $this->scopeKey(
                (int) $row['taxon_id'],
                $this->nullableInt($row['geographic_region_id'] ?? null),
            );
            $computedScopes[$key] = true;
            $existingRow = $existingByScope[$key] ?? [];
            $row['key_species'] = $existingRow['key_species'] ?? null;
            $row['key_species_comment'] = $existingRow['key_species_comment'] ?? null;
            $row['key_species_decision_date'] = $existingRow['key_species_decision_date'] ?? null;
        }
        unset($row);

        foreach ($existingByScope as $key => $existingRow) {
            if (isset($computedScopes[$key])) {
                continue;
            }
            if ($existingRow['key_species'] === null
                && $existingRow['key_species_comment'] === null
                && $existingRow['key_species_decision_date'] === null) {
                continue;
            }

            $rows[] = [
                'taxon_id' => (int) $existingRow['taxon_id'],
                'geographic_region_id' => $existingRow['geographic_region_id'] === null
                    ? null
                    : (int) $existingRow['geographic_region_id'],
                'occurrences_count' => 0,
                'grid_square_count' => 0,
                'mean_squares_per_year' => 0,
                'mean_occurrences_per_year' => 0,
                'first_record_date' => null,
                'last_record_date' => null,
                'first_recorder' => '',
                'last_recorder' => '',
                'first_verified_record_date' => null,
                'last_verified_record_date' => null,
                'first_verified_recorder' => '',
                'last_verified_recorder' => '',
                'key_species' => $existingRow['key_species'],
                'key_species_comment' => $existingRow['key_species_comment'],
                'key_species_decision_date' => $existingRow['key_species_decision_date'],
            ];
        }

        return $rows;
    }

    /**
     * Build recomputed taxon stats rows.
     *
     * @param array{columns: array<int, string>, include_exact: bool} $batch Projection definition.
     * @param array<string, mixed>|null $scope Optional single dirty scope.
     * @param array<int, int> $taxonIds Optional taxon chunk for a full rebuild.
     * @return array<int, array<string, mixed>> Recomputed aggregate rows.
     */
    private function buildRows(array $batch, ?array $scope = null, array $taxonIds = []): array
    {
        $db = db_connect();
        $prefix = $db->getPrefix();
        $reportingColumns = $batch['columns'];
        $projectionColumns = $reportingColumns === [] ? '' : ",\n                    " . implode(",\n                    ", array_map(static fn (string $column): string => 'o.' . $column, $reportingColumns));
        $taxonFilter = '';
        $scopeFilter = '';
        if ($taxonIds !== []) {
            $taxonColumn = $batch['include_exact'] ? 'o.taxon_id' : 'o.' . $reportingColumns[0];
            $taxonIds = array_values(array_unique(array_filter(
                array_map('intval', $taxonIds),
                static fn (int $id): bool => $id > 0,
            )));
            $taxonFilter = ' AND ' . $taxonColumn . ' IN (' . implode(',', $taxonIds) . ')';
        }
        if ($scope !== null) {
            $scopeTaxonId = (int) ($scope['taxon_id'] ?? 0);
            $scopeTaxonColumn = $batch['include_exact'] ? 'o.taxon_id' : 'o.' . $reportingColumns[0];
            $scopeFilter = ' AND ' . $scopeTaxonColumn . ' = ' . $scopeTaxonId;
            if ($scope['geographic_region_id'] !== null) {
                $scopeFilter .= ' AND EXISTS (
                    SELECT 1
                    FROM ' . $prefix . 'geographic_regions_occurrences scope_gro
                    WHERE scope_gro.occurrence_id = o.id
                        AND scope_gro.geographic_region_id = ' . (int) $scope['geographic_region_id'] . '
                )';
            }
        }
        $scopedOccurrences = $this->scopedOccurrenceSql($prefix, $reportingColumns, $batch['include_exact']);
        $scopedTable = 'scoped_occurrences';
        $scopeCte = '';
        if ($scope !== null) {
            $scopeRegion = $scope['geographic_region_id'] === null ? 'IS NULL' : '= ' . (int) $scope['geographic_region_id'];
            $scopedTable = 'filtered_scoped_occurrences';
            $scopeCte = ', filtered_scoped_occurrences AS (
                SELECT * FROM scoped_occurrences
                WHERE taxon_id = ' . $scopeTaxonId . ' AND geographic_region_id ' . $scopeRegion . '
            )';
        }
        $rows = $db->query(
            'WITH active_occurrences AS (
                SELECT
                    o.id AS occurrence_id,
                    o.taxon_id,
                    tr.is_reporting,
                    COALESCE(o.from_date, o.to_date) AS first_record_date,
                    COALESCE(o.to_date, o.from_date) AS last_record_date,
                    CASE
                        WHEN o.grid_ref_2km IS NULL OR TRIM(o.grid_ref_2km) = "" THEN NULL
                        ELSE UPPER(TRIM(o.grid_ref_2km))
                    END AS grid_ref_2km,
                    COALESCE(TRIM(o.recorded_by), "") AS recorded_by,
                    COALESCE(o.identification_verification_status, "") AS identification_verification_status' . $projectionColumns . '
                FROM ' . $prefix . 'occurrences o
                INNER JOIN ' . $prefix . 'taxa t
                    ON t.id = o.taxon_id
                    AND t.deleted_at IS NULL
                    AND t.blocked = 0
                INNER JOIN ' . $prefix . 'taxon_ranks tr
                    ON tr.id = t.taxon_rank_id
                WHERE o.deleted_at IS NULL
                    AND o.blocked = 0' . $taxonFilter . $scopeFilter . '
            ),
            scoped_occurrences AS (' . $scopedOccurrences . ')' . $scopeCte . ',
            aggregates AS (
                SELECT
                    so.taxon_id,
                    so.geographic_region_id,
                    COUNT(*) AS occurrences_count,
                    COUNT(DISTINCT so.grid_ref_2km) AS grid_square_count,
                    MIN(so.first_record_date) AS first_record_date,
                    MAX(so.last_record_date) AS last_record_date,
                    MIN(CASE WHEN so.identification_verification_status LIKE "V%" THEN so.first_record_date END) AS first_verified_record_date,
                    MAX(CASE WHEN so.identification_verification_status LIKE "V%" THEN so.last_record_date END) AS last_verified_record_date
                FROM ' . $scopedTable . ' so
                GROUP BY so.taxon_id, so.geographic_region_id
            )
            SELECT
                a.taxon_id,
                a.geographic_region_id,
                a.occurrences_count,
                a.grid_square_count,
                a.first_record_date,
                a.last_record_date,
                MIN(CASE WHEN so.first_record_date = a.first_record_date THEN so.occurrence_id END) AS first_occurrence_id,
                MAX(CASE WHEN so.last_record_date = a.last_record_date THEN so.occurrence_id END) AS last_occurrence_id,
                MIN(CASE WHEN so.first_record_date = a.first_verified_record_date
                    AND so.identification_verification_status LIKE "V%" THEN so.occurrence_id END) AS first_verified_occurrence_id,
                MAX(CASE WHEN so.last_record_date = a.last_verified_record_date
                    AND so.identification_verification_status LIKE "V%" THEN so.occurrence_id END) AS last_verified_occurrence_id
            FROM aggregates a
            INNER JOIN ' . $scopedTable . ' so
                ON so.taxon_id = a.taxon_id
                AND (
                    so.geographic_region_id = a.geographic_region_id
                    OR (so.geographic_region_id IS NULL AND a.geographic_region_id IS NULL)
                )
            GROUP BY
                a.taxon_id,
                a.geographic_region_id,
                a.occurrences_count,
                a.grid_square_count,
                a.first_record_date,
                a.last_record_date,
                a.first_verified_record_date,
                a.last_verified_record_date'
        )->getResultArray();

        $rows = $this->addYearlyAverages($rows);

        return $this->addBoundaryOccurrenceDetails($rows);
    }

    /**
     * Refresh yearly averages for existing taxon-stat rows after yearly stats are published.
     *
     * @return void
     */
    public function refreshYearlyAverages(): void
    {
        $db = db_connect();
        [$firstYear, $currentYear, $yearCount] = $this->yearlyAverageWindow();
        $statsTable = $db->getPrefix() . 'taxon_stats';

        if ($yearCount === 0) {
            $db->query(
                'UPDATE ' . $statsTable . '
                SET mean_squares_per_year = 0, mean_occurrences_per_year = 0'
            );

            return;
        }

        $yearTable = $db->getPrefix() . 'taxon_year_stats';
        $scopeCondition = '(yearly.geographic_region_id = ' . $statsTable . '.geographic_region_id
            OR (yearly.geographic_region_id IS NULL AND '
                . $statsTable . '.geographic_region_id IS NULL))';
        $db->query(
            'UPDATE ' . $statsTable . '
            SET mean_squares_per_year = COALESCE((
                    SELECT ROUND(1.0 * SUM(yearly.grid_square_count) / ?, 4)
                    FROM ' . $yearTable . ' yearly
                    WHERE yearly.taxon_id = ' . $statsTable . '.taxon_id
                        AND ' . $scopeCondition . '
                        AND yearly.year >= ? AND yearly.year < ?
                ), 0),
                mean_occurrences_per_year = COALESCE((
                    SELECT ROUND(1.0 * SUM(yearly.occurrences_count) / ?, 4)
                    FROM ' . $yearTable . ' yearly
                    WHERE yearly.taxon_id = ' . $statsTable . '.taxon_id
                        AND ' . $scopeCondition . '
                        AND yearly.year >= ? AND yearly.year < ?
                ), 0)',
            [$yearCount, $firstYear, $currentYear, $yearCount, $firstYear, $currentYear],
        );
    }

    /**
     * Add annual averages from the configured completed-year window to aggregate rows.
     *
     * Missing taxon-year rows contribute zero because the divisor is the full year-window
     * length, not the number of rows present.
     *
     * @param array<int, array<string, mixed>> $rows Taxon and optional region scopes.
     * @return array<int, array<string, mixed>> Rows with both yearly averages.
     */
    private function addYearlyAverages(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        [$firstYear, $currentYear, $yearCount] = $this->yearlyAverageWindow();
        $taxonIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['taxon_id'],
            $rows,
        )));
        $averages = [];

        if ($yearCount > 0) {
            $yearRows = db_connect()->table('taxon_year_stats')
                ->select(
                    'taxon_id, geographic_region_id, SUM(grid_square_count) AS squares_total, '
                        . 'SUM(occurrences_count) AS occurrences_total',
                    false,
                )
                ->whereIn('taxon_id', $taxonIds)
                ->where('year >=', $firstYear)
                ->where('year <', $currentYear)
                ->groupBy('taxon_id, geographic_region_id')
                ->get()
                ->getResultArray();

            foreach ($yearRows as $yearRow) {
                $averages[$this->scopeKey(
                    (int) $yearRow['taxon_id'],
                    $this->nullableInt($yearRow['geographic_region_id'] ?? null),
                )] = [
                    'squares' => (float) ($yearRow['squares_total'] ?? 0),
                    'occurrences' => (float) ($yearRow['occurrences_total'] ?? 0),
                ];
            }
        }

        foreach ($rows as &$row) {
            $totals = $averages[$this->scopeKey(
                (int) $row['taxon_id'],
                $this->nullableInt($row['geographic_region_id'] ?? null),
            )] ?? ['squares' => 0.0, 'occurrences' => 0.0];

            $row['mean_squares_per_year'] = $yearCount > 0
                ? round($totals['squares'] / $yearCount, 4)
                : 0;
            $row['mean_occurrences_per_year'] = $yearCount > 0
                ? round($totals['occurrences'] / $yearCount, 4)
                : 0;
        }
        unset($row);

        return $rows;
    }

    /**
     * Resolve the configured completed-year range, excluding the current year.
     *
     * @return array{0: int, 1: int, 2: int} First year, current year and completed-year count.
     */
    private function yearlyAverageWindow(): array
    {
        $currentYear = (int) date('Y');
        $historyYears = (int) config(TaxonYearStats::class)->historyYears;

        if ($historyYears > 0) {
            $firstYear = $currentYear - $historyYears;
        } else {
            $row = db_connect()->table('taxon_year_stats')
                ->selectMin('year', 'first_year')
                ->where('year <', $currentYear)
                ->get()
                ->getRowArray();
            $firstYear = (int) ($row['first_year'] ?? $currentYear);
        }

        return [$firstYear, $currentYear, max(0, $currentYear - $firstYear)];
    }

    /**
     * Return the ordered rank and exact batches used by the derived import.
     *
     * @return array<int, array{columns: array<int, string>, include_exact: bool}> Batches.
     */
    private function statBatches(): array
    {
        $batches = array_map(
            static fn (string $column): array => ['columns' => [$column], 'include_exact' => false],
            $this->reportingColumns(),
        );
        $batches[] = ['columns' => [], 'include_exact' => true];

        return $batches;
    }

    /**
     * Add recorder and verified boundary details selected by the aggregate query.
     *
     * @param array<int, array<string, mixed>> $rows Aggregate rows.
     *
     * @return array<int, array<string, mixed>> Rows with record details populated.
     */
    private function addBoundaryOccurrenceDetails(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $occurrenceIds = [];
        foreach ($rows as $row) {
            foreach (['first_occurrence_id', 'last_occurrence_id', 'first_verified_occurrence_id', 'last_verified_occurrence_id'] as $field) {
                $occurrenceId = (int) ($row[$field] ?? 0);
                if ($occurrenceId > 0) {
                    $occurrenceIds[$occurrenceId] = $occurrenceId;
                }
            }
        }

        $details = [];
        if ($occurrenceIds !== []) {
            $db = db_connect();
            $details = $db->table('occurrences')
                ->select('id, COALESCE(from_date, to_date) AS first_record_date, COALESCE(to_date, from_date) AS last_record_date, COALESCE(TRIM(recorded_by), "") AS recorded_by')
                ->whereIn('id', array_values($occurrenceIds))
                ->get()
                ->getResultArray();
            $details = array_column($details, null, 'id');
        }

        foreach ($rows as &$row) {
            $first = $details[(int) ($row['first_occurrence_id'] ?? 0)] ?? [];
            $last = $details[(int) ($row['last_occurrence_id'] ?? 0)] ?? [];
            $firstVerified = $details[(int) ($row['first_verified_occurrence_id'] ?? 0)] ?? [];
            $lastVerified = $details[(int) ($row['last_verified_occurrence_id'] ?? 0)] ?? [];

            $row['first_recorder'] = (string) ($first['recorded_by'] ?? '');
            $row['last_recorder'] = (string) ($last['recorded_by'] ?? '');
            $row['first_verified_record_date'] = $firstVerified['first_record_date'] ?? null;
            $row['last_verified_record_date'] = $lastVerified['last_record_date'] ?? null;
            $row['first_verified_recorder'] = (string) ($firstVerified['recorded_by'] ?? '');
            $row['last_verified_recorder'] = (string) ($lastVerified['recorded_by'] ?? '');

            unset(
                $row['first_occurrence_id'],
                $row['last_occurrence_id'],
                $row['first_verified_occurrence_id'],
                $row['last_verified_occurrence_id'],
            );
        }
        unset($row);

        return $rows;
    }

    /**
     * Return physical occurrence projection columns for configured reporting ranks.
     *
     * @return array<int, string> Normalised projection column names.
     */
    private function reportingColumns(): array
    {
        $ranks = config('Import')->taxonRanks ?? [];
        $ranks = is_array($ranks) ? $ranks : explode(',', (string) $ranks);

        return array_values(array_filter(array_map(static function ($rank): string {
            if (! is_scalar($rank)) {
                return '';
            }

            $column = preg_replace('/[^a-z0-9]+/i', '_', strtolower(trim((string) $rank)));

            return trim((string) $column, '_') . '_id';
        }, $ranks)));
    }

    /**
     * Parse a compatible full-rebuild checkpoint.
     *
     * Numeric checkpoints were written by earlier versions and point to the start of a rank
     * projection. New checkpoints add the last completed taxon ID after a colon.
     *
     * @param string|null $checkpoint Persisted task checkpoint.
     * @param int          $batchCount Number of reporting projections.
     * @return array{0: int, 1: int} Projection index and last processed taxon ID.
     *
     * @throws RuntimeException When the checkpoint is not a supported format.
     */
    private function parseRebuildCheckpoint(?string $checkpoint, int $batchCount): array
    {
        if ($checkpoint === null) {
            return [0, 0];
        }

        if (preg_match('/^(\d+)(?::(\d+))?$/', $checkpoint, $matches) !== 1) {
            throw new RuntimeException('Taxon statistics checkpoint is invalid.');
        }

        $batchIndex = max(0, min((int) $matches[1], $batchCount - 1));
        $afterTaxonId = isset($matches[2]) ? (int) $matches[2] : 0;

        return [$batchIndex, $afterTaxonId];
    }

    /**
     * Find the next ordered set of active occurrence taxon IDs for one projection.
     *
     * @param array{columns: array<int, string>, include_exact: bool} $batch Projection definition.
     * @param int   $afterTaxonId Only return IDs greater than this cursor.
     * @param int   $limit Maximum IDs to return.
     * @return array{0: array<int, int>, 1: bool} IDs and whether more IDs remain.
     */
    private function nextTaxonIds(array $batch, int $afterTaxonId, int $limit): array
    {
        $builder = db_connect()->table('occurrences o');
        if ($batch['include_exact']) {
            $builder->select('o.taxon_id AS taxon_id')
                ->join('taxa t', 't.id = o.taxon_id')
                ->join('taxon_ranks tr', 'tr.id = t.taxon_rank_id')
                ->where('tr.is_reporting', 0)
                ->where('o.taxon_id >', $afterTaxonId)
                ->groupBy('o.taxon_id')
                ->orderBy('o.taxon_id', 'ASC');
        } else {
            $column = $batch['columns'][0];
            $builder->select('o.' . $column . ' AS taxon_id', false)
                ->where('o.' . $column . ' >', $afterTaxonId)
                ->where('o.' . $column . ' IS NOT NULL', null, false)
                ->groupBy('o.' . $column)
                ->orderBy('o.' . $column, 'ASC');
        }

        $rows = $builder
            ->where('o.deleted_at', null)
            ->where('o.blocked', 0)
            ->limit($limit + 1)
            ->get()
            ->getResultArray();
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $taxonIds = array_map(static fn (array $row): int => (int) $row['taxon_id'], $rows);

        return [$taxonIds, $hasMore];
    }

    /**
     * Keep a rebuild invocation below both its configured budget and PHP's execution limit.
     *
     * @param int $configuredSeconds Configured maximum runtime.
     * @return float Effective runtime budget in seconds.
     */
    private function runtimeBudget(int $configuredSeconds): float
    {
        $phpLimit = (int) ini_get('max_execution_time');
        if ($phpLimit <= 0) {
            return (float) $configuredSeconds;
        }

        return max(1.0, min((float) $configuredSeconds, (float) ($phpLimit - 5)));
    }

    /**
     * Calculate frequency trends for species in each global or regional scope.
     *
    * @return array<string, array{score: int|null, state: string}> Trend results keyed by taxon and region.
     */
    private function frequencyTrends(): array
    {
        $db = db_connect();
        $prefix = $db->getPrefix();
        $config = config(TaxonFrequencyTrend::class);
        $currentYear = (int) date('Y');
        $firstYear = $currentYear - (int) $config->analysisYears;
        // Note the query must limit to < $currentYear to avoid including
        // incomplete data for the current year.
        $rows = $db->query(
            'SELECT
                tys.taxon_id,
                tys.geographic_region_id,
                t.taxon_rank_id,
                tys.year,
                tys.occurrences_count,
                tys.grid_square_count
            FROM ' . $prefix . 'taxon_year_stats tys
            INNER JOIN ' . $prefix . 'taxa t
                ON t.id = tys.taxon_id
                AND t.deleted_at IS NULL
                AND t.blocked = 0
            INNER JOIN ' . $prefix . 'taxon_ranks tr
                ON tr.id = t.taxon_rank_id
                AND tr.is_reporting = 1
            WHERE tys.year >= ? AND tys.year < ?
            ORDER BY tys.taxon_id, tys.geographic_region_id, tys.year'
        , [$firstYear, $currentYear])->getResultArray();

        $scopes = [];

        foreach ($rows as $row) {
            $taxonId = (int) ($row['taxon_id'] ?? 0);
            $regionId = $this->nullableInt($row['geographic_region_id'] ?? null);
            $year = (int) ($row['year'] ?? 0);

            if ($taxonId <= 0 || $year <= 0) {
                continue;
            }

            $scopeKey = $this->scopeKey($taxonId, $regionId);
            $scopes[$scopeKey]['taxon_id'] = $taxonId;
            $scopes[$scopeKey]['geographic_region_id'] = $regionId;
            $scopes[$scopeKey]['taxon_rank_id'] = (int) ($row['taxon_rank_id'] ?? 0);
            $scopes[$scopeKey]['years'][$year] = [
                'grid_square_count' => max(0, (int) ($row['grid_square_count'] ?? 0)),
                'occurrences_count' => max(0, (int) ($row['occurrences_count'] ?? 0)),
            ];
        }

        $trends = [];
        $groupedScopes = [];

        foreach ($scopes as $scope) {
            $scopeKey = $this->scopeKey((int) $scope['taxon_id'], $scope['geographic_region_id']);
            $groupKey = ($scope['geographic_region_id'] ?? 'global') . '|' . $scope['taxon_rank_id'];
            $groupedScopes[$groupKey][$scopeKey] = $scope;
        }

        foreach ($groupedScopes as $scopeRows) {
            $scoredRows = [];

            foreach ($scopeRows as $scopeKey => $scope) {
                $squareSeries = [];
                $occurrenceSeries = [];

                for ($year = $firstYear; $year < $currentYear; $year++) {
                    $values = $scope['years'][$year] ?? [
                        'grid_square_count' => 0,
                        'occurrences_count' => 0,
                    ];
                    $squareSeries[$year] = (int) $values['grid_square_count'];
                    $occurrenceSeries[$year] = (int) $values['occurrences_count'];
                }

                $squareRegression = $this->regression($squareSeries, (float) $config->recentYearHalfLife);
                $occurrenceRegression = $this->regression($occurrenceSeries, (float) $config->recentYearHalfLife);

                $scoredRows[$scopeKey] = [
                    'taxon_id' => (int) $scope['taxon_id'],
                    'geographic_region_id' => $scope['geographic_region_id'],
                    'taxon_rank_id' => $scope['taxon_rank_id'],
                    'occupied_years' => count(array_filter(
                        $scope['years'],
                        static fn (array $values): bool => $values['grid_square_count'] > 0
                            || $values['occurrences_count'] > 0,
                    )),
                    'square_slope' => $squareRegression['slope'],
                    'square_r_squared' => $squareRegression['r_squared'],
                    'occurrence_slope' => $occurrenceRegression['slope'],
                    'occurrence_r_squared' => $occurrenceRegression['r_squared'],
                ];
            }

            $gridSquareWeight = (float) $config->gridSquareWeight;
            $occurrenceWeight = (float) $config->occurrenceWeight;
            $totalWeight = $gridSquareWeight + $occurrenceWeight;

            foreach ($scoredRows as $scopeKey => &$scoredRow) {
                $scoredRow['direction'] = (($scoredRow['square_slope'] * $gridSquareWeight)
                    + ($scoredRow['occurrence_slope'] * $occurrenceWeight)) / $totalWeight;
                $scoredRow['r_squared'] = (($scoredRow['square_r_squared'] * $gridSquareWeight)
                    + ($scoredRow['occurrence_r_squared'] * $occurrenceWeight)) / $totalWeight;
                $scoredRow['state'] = $this->classifyTrend($scoredRow, $config);
                $scoredRow['is_directional'] = in_array($scoredRow['state'], ['increasing', 'decreasing'], true);
                $trends[$scopeKey] = [
                    'score' => $scoredRow['state'] === 'stable' ? 50 : null,
                    'state' => $scoredRow['state'],
                ];
            }
            unset($scoredRow);

            $directionalRows = array_filter(
                $scoredRows,
                static fn (array $row): bool => $row['is_directional'],
            );
            $squareRanks = $this->denseFloatRanks($directionalRows, 'square_slope');
            $occurrenceRanks = $this->denseFloatRanks($directionalRows, 'occurrence_slope');

            foreach ($directionalRows as $scopeKey => &$directionalRow) {
                $directionalRow['combined_rank'] = ($squareRanks[$scopeKey] * $gridSquareWeight)
                    + ($occurrenceRanks[$scopeKey] * $occurrenceWeight);
            }
            unset($directionalRow);

            $positive = array_filter($directionalRows, static fn (array $row): bool => $row['direction'] > 0);
            $negative = array_filter($directionalRows, static fn (array $row): bool => $row['direction'] < 0);

            $this->assignDirectionalTrends($trends, $positive, 50, 100);
            $this->assignDirectionalTrends($trends, $negative, 0, 50);
        }

        return $trends;
    }

    /**
    * Calculate least-squares slope and coefficient of determination for a time series.
     *
    * @param array<int, int> $series   Values keyed by year.
    * @param float            $halfLife Recent-year weighting half-life.
     *
     * @return array{slope: float, r_squared: float} Regression statistics.
     */
    private function regression(array $series, float $halfLife): array
    {
        $count = count($series);

        if ($count < 2) {
            return ['slope' => 0.0, 'r_squared' => 0.0];
        }

        $latestYear = max(array_keys($series));
        $weights = [];
        foreach ($series as $year => $value) {
            $weights[$year] = $halfLife > 0.0 ? 2 ** (-($latestYear - $year) / $halfLife) : 1.0;
        }
        $totalWeight = array_sum($weights);
        $meanX = 0.0;
        $meanY = 0.0;
        foreach ($series as $year => $value) {
            $meanX += $weights[$year] * $year;
            $meanY += $weights[$year] * $value;
        }
        $meanX /= $totalWeight;
        $meanY /= $totalWeight;
        $numerator = 0.0;
        $denominator = 0.0;

        foreach ($series as $year => $value) {
            $xDifference = $year - $meanX;
            $numerator += $weights[$year] * $xDifference * ($value - $meanY);
            $denominator += $weights[$year] * $xDifference * $xDifference;
        }

        if ($denominator === 0.0) {
            return ['slope' => 0.0, 'r_squared' => 0.0];
        }

        $slope = $numerator / $denominator;
        $intercept = $meanY - ($slope * $meanX);
        $residualSumSquares = 0.0;
        $totalSumSquares = 0.0;

        foreach ($series as $year => $value) {
            $residual = $value - ($intercept + ($slope * $year));
            $residualSumSquares += $weights[$year] * $residual * $residual;
            $differenceFromMean = $value - $meanY;
            $totalSumSquares += $weights[$year] * $differenceFromMean * $differenceFromMean;
        }

        $rSquared = $totalSumSquares <= PHP_FLOAT_EPSILON
            ? 1.0
            : 1.0 - ($residualSumSquares / $totalSumSquares);

        return [
            'slope' => $slope,
            'r_squared' => max(0.0, min(1.0, $rSquared)),
        ];
    }

    /**
     * Classify a trend using evidence thresholds and the weighted slope.
     *
     * @param array<string, mixed> $row    Scored trend metrics.
     * @param TaxonFrequencyTrend  $config Trend configuration.
     *
     * @return string Trend state.
     */
    private function classifyTrend(array $row, TaxonFrequencyTrend $config): string
    {
        if ((int) $row['occupied_years'] < $config->minimumOccupiedYears) {
            return 'insufficient_data';
        }

        if ((float) $row['r_squared'] < $config->minimumRSquared) {
            return 'unclear';
        }

        if (abs((float) $row['direction']) <= $config->slopeTolerance) {
            return 'stable';
        }

        return (float) $row['direction'] > 0 ? 'increasing' : 'decreasing';
    }

    /**
     * Compute dense ascending ranks for a floating-point metric.
     *
     * @param array<string, array<string, mixed>> $rows  Rows to rank.
     * @param string                              $metric Metric key.
     *
     * @return array<string, int> Ranks keyed by scope.
     */
    private function denseFloatRanks(array $rows, string $metric): array
    {
        $values = array_map(static fn (array $row): float => (float) $row[$metric], $rows);
        $uniqueValues = array_values(array_unique($values, SORT_REGULAR));
        sort($uniqueValues, SORT_NUMERIC);
        $ranks = [];

        foreach ($rows as $scopeKey => $row) {
            $value = (float) $row[$metric];
            $rank = array_search($value, $uniqueValues, true);
            $ranks[$scopeKey] = ($rank === false ? 0 : $rank) + 1;
        }

        return $ranks;
    }

    /**
     * Map one directional group onto its half of the frequency trend scale.
     *
    * @param array<string, array{score: int|null, state: string}> $trends Destination trend values.
     * @param array<string, array<string, mixed>> $rows   Directional scored rows.
     * @param int                                 $minimum Lower scale endpoint.
     * @param int                                 $maximum Upper scale endpoint.
     *
     * @return void
     */
    private function assignDirectionalTrends(array &$trends, array $rows, int $minimum, int $maximum): void
    {
        if ($rows === []) {
            return;
        }

        $scores = array_map(static fn (array $row): float => (float) $row['combined_rank'], $rows);
        $minimumScore = min($scores);
        $maximumScore = max($scores);

        foreach ($rows as $scopeKey => $row) {
            if ($minimumScore === $maximumScore) {
                $trend = $maximum > 50 ? $maximum : $minimum;
            } else {
                $position = ((float) $row['combined_rank'] - $minimumScore)
                    / ($maximumScore - $minimumScore);
                $trend = (int) round($minimum + ($position * ($maximum - $minimum)));
            }

            $trends[$scopeKey] = [
                'score' => max(0, min(100, $trend)),
                'state' => $row['state'],
            ];
        }
    }

    /**
     * Build a stable key for a taxon and global or regional scope.
     *
     * @param int      $taxonId   Taxon identifier.
     * @param int|null $regionId Geographic region identifier.
     *
     * @return string Scope key.
     */
    private function scopeKey(int $taxonId, ?int $regionId): string
    {
        return $taxonId . '|' . ($regionId ?? 'global');
    }

    /**
     * Build the exact and reporting-projection occurrence scope.
     *
     * @param string             $prefix Database table prefix.
     * @param array<int, string> $columns Reporting projection columns.
     * @return string SQL for the scoped occurrence CTE body.
     */
    private function scopedOccurrenceSql(string $prefix, array $columns, bool $includeExact = true): string
    {
        $selects = [];
        if ($includeExact) {
            $selects = [
                'SELECT ao.occurrence_id, ao.taxon_id, gro.geographic_region_id,
                    ao.first_record_date, ao.last_record_date, ao.grid_ref_2km, ao.recorded_by,
                    ao.identification_verification_status
                 FROM active_occurrences ao
                 INNER JOIN ' . $prefix . 'geographic_regions_occurrences gro
                    ON gro.occurrence_id = ao.occurrence_id
                 WHERE ao.is_reporting = 0',
                'SELECT ao.occurrence_id, ao.taxon_id, NULL AS geographic_region_id,
                    ao.first_record_date, ao.last_record_date, ao.grid_ref_2km, ao.recorded_by,
                    ao.identification_verification_status
                 FROM active_occurrences ao
                 WHERE ao.is_reporting = 0',
            ];
        }

        foreach ($columns as $column) {
            $selects[] = 'SELECT ao.occurrence_id, ao.' . $column . ' AS taxon_id,
                gro.geographic_region_id, ao.first_record_date, ao.last_record_date, ao.grid_ref_2km,
                ao.recorded_by, ao.identification_verification_status
             FROM active_occurrences ao
             INNER JOIN ' . $prefix . 'geographic_regions_occurrences gro
                ON gro.occurrence_id = ao.occurrence_id
             WHERE ao.' . $column . ' IS NOT NULL';
            $selects[] = 'SELECT ao.occurrence_id, ao.' . $column . ' AS taxon_id,
                NULL AS geographic_region_id, ao.first_record_date, ao.last_record_date, ao.grid_ref_2km,
                ao.recorded_by, ao.identification_verification_status
             FROM active_occurrences ao
             WHERE ao.' . $column . ' IS NOT NULL';
        }

        return implode("\n                UNION\n                ", $selects);
    }

    /**
     * Insert computed rows into taxon_stats.
     *
     * @param array<int, array<string, mixed>> $rows Computed rows.
     * @param array<string, array{score: int|null, state: string}>|null $frequencyTrends
     *        Precomputed trend map, or null to calculate it for this insert.
     *
     * @return void
     */
    private function insertRows(array $rows, ?array $frequencyTrends = null): void
    {
        $db = db_connect();
        $payload = [];
        $frequencyTrends ??= $this->frequencyTrends();

        foreach ($rows as $row) {
            $taxonId = (int) ($row['taxon_id'] ?? 0);
            $geographicRegionId = $this->nullableInt($row['geographic_region_id'] ?? null);

            if ($taxonId <= 0) {
                continue;
            }

            $payload[] = [
                'uuid' => TaxonStatsIdentity::uuidForScope($taxonId, $geographicRegionId),
                'taxon_id' => $taxonId,
                'geographic_region_id' => $geographicRegionId,
                'frequency_trend' => $frequencyTrends[$this->scopeKey($taxonId, $geographicRegionId)]['score'] ?? null,
                'frequency_trend_state' => $frequencyTrends[$this->scopeKey($taxonId, $geographicRegionId)]['state'] ?? null,
                'occurrences_count' => max(0, (int) ($row['occurrences_count'] ?? 0)),
                'grid_square_count' => max(0, (int) ($row['grid_square_count'] ?? 0)),
                'mean_squares_per_year' => max(0, (float) ($row['mean_squares_per_year'] ?? 0)),
                'mean_occurrences_per_year' => max(0, (float) ($row['mean_occurrences_per_year'] ?? 0)),
                'first_record_date' => $row['first_record_date'] ?? null,
                'last_record_date' => $row['last_record_date'] ?? null,
                'first_recorder' => substr((string) ($row['first_recorder'] ?? ''), 0, 255),
                'last_recorder' => substr((string) ($row['last_recorder'] ?? ''), 0, 255),
                'first_verified_record_date' => $row['first_verified_record_date'] ?? null,
                'last_verified_record_date' => $row['last_verified_record_date'] ?? null,
                'first_verified_recorder' => substr((string) ($row['first_verified_recorder'] ?? ''), 0, 255),
                'last_verified_recorder' => substr((string) ($row['last_verified_recorder'] ?? ''), 0, 255),
                'key_species' => $row['key_species'] ?? null,
                'key_species_comment' => $row['key_species_comment'] ?? null,
                'key_species_decision_date' => $row['key_species_decision_date'] ?? null,
            ];
        }

        if ($payload === []) {
            return;
        }

        $chunks = array_chunk($payload, 500);

        foreach ($chunks as $chunk) {
            $db->table('taxon_stats')->insertBatch($chunk);
        }
    }

    /**
     * Convert mixed values to nullable integer.
     *
     * @param mixed $value Raw value.
     *
     * @return int|null
     */
    private function nullableInt(mixed $value): ?int
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '' || ! is_numeric($string)) {
            return null;
        }

        return (int) $string;
    }

}
