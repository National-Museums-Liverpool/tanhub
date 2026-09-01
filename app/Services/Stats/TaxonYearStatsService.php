<?php

namespace App\Services\Stats;

use Config\TaxonYearStats;

/**
 * Recomputes sparse taxon_year_stats aggregates using resumable staging batches.
 */
class TaxonYearStatsService
{
    private const SOURCE_KEY = 'derived-stats:taxon_year_stats';

    /**
     * Create the taxon-year statistics service.
     *
     * @param StatsDirtyScopeService|null $dirtyScopeService Dirty queue service.
     */
    public function __construct(private readonly ?StatsDirtyScopeService $dirtyScopeService = null)
    {
    }

    /**
     * Recompute one bounded taxon year stats batch.
     *
     * @param bool $dryRun Whether persistence is disabled for this run.
     *
     * @return array<string, int|string|bool> Batch result and continuation state.
     */
    public function run(bool $dryRun = false): array
    {
        $config = config(TaxonYearStats::class);
        $db = db_connect();

        if ($this->state() !== null || $db->table('taxon_year_stats')->countAllResults() === 0) {
            $startedAt = microtime(true);
            $result = $this->runFullRebuild($dryRun, $config);

            while (! $dryRun && ($result['has_more'] ?? false)
                && microtime(true) - $startedAt < $config->maxRuntimeSeconds) {
                $next = $this->runFullRebuild($dryRun, $config);
                foreach (['fetched', 'processed', 'inserted', 'updated', 'not changed', 'skipped', 'errors'] as $key) {
                    $result[$key] = (int) ($result[$key] ?? 0) + (int) ($next[$key] ?? 0);
                }
                $result['status'] = $next['status'] ?? $result['status'];
                $result['has_more'] = $next['has_more'] ?? false;

                if (($next['status'] ?? 'failed') !== 'success') {
                    break;
                }
            }

            return $result;
        }

        return $this->runIncremental($dryRun, $config);
    }

    /**
     * Execute one resumable staging rebuild batch.
     *
     * @param bool           $dryRun Whether persistence is disabled.
     * @param TaxonYearStats $config Rebuild configuration.
     * @return array<string, int|string|bool> Batch result and continuation state.
     */
    private function runFullRebuild(bool $dryRun, TaxonYearStats $config): array
    {
        $counts = ['status' => 'success', 'fetched' => 0, 'processed' => 0, 'inserted' => 0,
            'updated' => 0, 'not changed' => 0, 'skipped' => 0, 'errors' => 0, 'has_more' => false];

        try {
            $db = db_connect();
            $columns = $this->reportingColumns();
            $projectionCount = count($columns) + 1;
            $years = $this->completedYears($config->historyYears);
            $state = $this->state();
            if ($state === null || (int) ($state['projection_count'] ?? 0) !== $projectionCount
                || (int) ($state['first_year'] ?? 0) !== ($years[0] ?? 0)
                || (int) ($state['last_year'] ?? 0) !== ($years[count($years) - 1] ?? 0)) {
                $state = ['build_id' => $this->stableUuid((string) microtime(true)), 'projection' => 0,
                    'year' => $years[0] ?? 0, 'projection_count' => $projectionCount,
                    'first_year' => $years[0] ?? 0, 'last_year' => $years[count($years) - 1] ?? 0];
                if (! $dryRun) {
                    $db->table('taxon_year_stats_build')->emptyTable();
                    $this->saveState($state);
                }
            }

            if ($years === [] || (int) $state['projection'] >= $projectionCount) {
                if (! $dryRun) {
                    $this->publish((string) $state['build_id']);
                    $this->clearState();
                }
                return $counts;
            }

            $projection = (int) $state['projection'];
            $startYear = max((int) $state['year'], $years[0]);
            $endYear = min($startYear + $config->yearsPerRun - 1, $years[count($years) - 1]);
            $rows = $this->buildRows($projection, $columns, $startYear, $endYear, (string) $state['build_id']);
            $counts['fetched'] = count($rows);
            $counts['processed'] = count($rows);
            $counts['inserted'] = count($rows);
            $hasMore = $endYear < $years[count($years) - 1] || $projection + 1 < $projectionCount;
            $counts['has_more'] = $hasMore;
            if ($dryRun) {
                return $counts;
            }
            if ($rows !== []) {
                $db->table('taxon_year_stats_build')
                    ->where('build_id', (string) $state['build_id'])
                    ->where('projection', $projection)
                    ->where('year >=', $startYear)
                    ->where('year <=', $endYear)
                    ->delete();
                $db->table('taxon_year_stats_build')->insertBatch($rows);
            }
            if ($hasMore) {
                $state['projection'] = $endYear < $years[count($years) - 1] ? $projection : $projection + 1;
                $state['year'] = $state['projection'] === $projection ? $endYear + 1 : $years[0];
                $this->saveState($state);
            } else {
                $this->publish((string) $state['build_id']);
                $this->clearState();
            }
        } catch (\Throwable $exception) {
            log_message('error', $exception->getMessage());
            $counts['status'] = 'failed';
            $counts['errors'] = 1;
        }

        return $counts;
    }

    /**
     * Process queued scopes until the configured scope or runtime limit is reached.
     *
     * @param bool           $dryRun Whether persistence is disabled.
     * @param TaxonYearStats $config Incremental configuration.
     * @return array<string, int|string|bool> Incremental result and continuation state.
     */
    private function runIncremental(bool $dryRun, TaxonYearStats $config): array
    {
        $counts = ['status' => 'success', 'fetched' => 0, 'processed' => 0, 'inserted' => 0,
            'updated' => 0, 'not changed' => 0, 'skipped' => 0, 'errors' => 0, 'has_more' => false];
        $dirty = $this->dirtyScopeService ?? service('statsDirtyScopeService');
        $startedAt = microtime(true);

        try {
            $scopes = $dirty->next(StatsDirtyScopeService::TAXON_YEAR, $config->maxScopes);
            $counts['fetched'] = count($scopes);

            foreach ($scopes as $scope) {
                if ($counts['processed'] > 0 && microtime(true) - $startedAt >= $config->maxRuntimeSeconds) {
                    break;
                }

                $row = $this->buildDirtyRow($scope);
                $this->replaceDirtyRow($scope, $row, $dryRun);
                if (! $dryRun) {
                    $dirty->acknowledge([(int) $scope['id']]);
                }
                $counts['processed']++;
                $counts['updated']++;
                if ($row !== null) {
                    $counts['inserted']++;
                }
            }

            $counts['has_more'] = $dirty->hasDirtyScopes(StatsDirtyScopeService::TAXON_YEAR);
        } catch (\Throwable $exception) {
            log_message('error', $exception->getMessage());
            $counts['status'] = 'failed';
            $counts['errors'] = 1;
        }

        return $counts;
    }

    /**
     * Recompute one taxon/year/global-or-region scope.
     *
     * @param array<string, mixed> $scope Dirty queue row.
     * @return array<string, mixed>|null Aggregate row, or null when now empty.
     */
    private function buildDirtyRow(array $scope): ?array
    {
        $projection = (string) ($scope['projection'] ?? '');
        $taxonId = (int) ($scope['taxon_id'] ?? 0);
        $regionId = $scope['geographic_region_id'] === null ? null : (int) $scope['geographic_region_id'];
        $year = (int) ($scope['year'] ?? 0);
        $columns = $this->reportingColumns();
        $db = db_connect();
        $prefix = $db->getPrefix();
        $yearExpression = strtoupper((string) ($db->DBDriver ?? '')) === 'SQLITE3'
            ? "CAST(strftime('%Y', COALESCE(o.from_date, o.to_date)) AS INTEGER)"
            : 'YEAR(COALESCE(o.from_date, o.to_date))';
        $projectionExpression = $projection === 'exact' ? 'o.taxon_id' : 'o.' . ($columns[array_search($projection, $columns, true)] ?? 'taxon_id');
        $condition = $projection === 'exact' ? 'tr.is_reporting = 0' : 'o.' . $projection . ' IS NOT NULL';
        $regionJoin = $regionId === null ? '' : ' INNER JOIN ' . $prefix . 'geographic_regions_occurrences gro ON gro.occurrence_id = o.id';
        $regionCondition = $regionId === null ? '' : ' AND gro.geographic_region_id = ' . $regionId;
        $row = $db->query(
            'SELECT ' . $projectionExpression . ' AS taxon_id, ' . ($regionId === null ? 'NULL' : (string) $regionId) . ' AS geographic_region_id,
                COUNT(*) AS occurrences_count,
                COUNT(DISTINCT NULLIF(UPPER(TRIM(o.grid_ref_2km)), "")) AS grid_square_count
             FROM ' . $prefix . 'occurrences o
             INNER JOIN ' . $prefix . 'taxa t ON t.id = o.taxon_id AND t.deleted_at IS NULL AND t.blocked = 0
             INNER JOIN ' . $prefix . 'taxon_ranks tr ON tr.id = t.taxon_rank_id' . $regionJoin . '
             WHERE o.deleted_at IS NULL AND o.blocked = 0
                AND COALESCE(o.from_date, o.to_date) IS NOT NULL
                AND ' . $condition . ' AND ' . $projectionExpression . ' = ?
                AND ' . $yearExpression . ' = ?' . $regionCondition,
            [$taxonId, $year],
        )->getRowArray();

        if (! is_array($row) || (int) ($row['occurrences_count'] ?? 0) === 0) {
            return null;
        }

        return [
            'uuid' => $this->stableUuid($taxonId . '|' . ($regionId ?? 'global') . '|' . $year),
            'taxon_id' => $taxonId,
            'geographic_region_id' => $regionId,
            'year' => $year,
            'occurrences_count' => (int) $row['occurrences_count'],
            'grid_square_count' => (int) $row['grid_square_count'],
        ];
    }

    /**
     * Replace one stored aggregate, deleting it when its scope is now empty.
     *
     * @param array<string, mixed>      $scope Dirty queue row.
     * @param array<string, mixed>|null  $row Recomputed aggregate.
     * @param bool                       $dryRun Whether persistence is disabled.
     * @return void
     */
    private function replaceDirtyRow(array $scope, ?array $row, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $db = db_connect();
        $builder = $db->table('taxon_year_stats')
            ->where('taxon_id', (int) $scope['taxon_id'])
            ->where('year', (int) $scope['year']);
        if ($scope['geographic_region_id'] === null) {
            $builder->where('geographic_region_id', null);
        } else {
            $builder->where('geographic_region_id', (int) $scope['geographic_region_id']);
        }
        $builder->delete();

        if ($row !== null) {
            $db->table('taxon_year_stats')->insert($row);
        }
    }

    /**
     * Build sparse rows for one projection and year range.
     *
     * @param int $projection Projection index; zero is exact taxon.
     * @param array<int, string> $columns Reporting columns.
     * @param int $startYear Inclusive first year.
     * @param int $endYear Inclusive last year.
     * @param string $buildId Staging build identifier.
     *
     * @return array<int, array<string, int|null|string>> Aggregate rows.
     */
    private function buildRows(int $projection, array $columns, int $startYear, int $endYear, string $buildId): array
    {
        $db = db_connect();
        $prefix = $db->getPrefix();
        $column = $projection === 0 ? 'taxon_id' : $columns[$projection - 1];
        $condition = $projection === 0 ? 'tr.is_reporting = 0' : 'o.' . $column . ' IS NOT NULL';
        $yearExpression = strtoupper((string) ($db->DBDriver ?? '')) === 'SQLITE3'
            ? "CAST(strftime('%Y', COALESCE(o.from_date, o.to_date)) AS INTEGER)" : 'YEAR(COALESCE(o.from_date, o.to_date))';
        $base = ' FROM ' . $prefix . 'occurrences o INNER JOIN ' . $prefix . 'taxa t ON t.id = o.taxon_id AND t.deleted_at IS NULL AND t.blocked = 0
            INNER JOIN ' . $prefix . 'taxon_ranks tr ON tr.id = t.taxon_rank_id LEFT JOIN ' . $prefix . 'geographic_regions_occurrences gro ON gro.occurrence_id = o.id
            WHERE o.deleted_at IS NULL AND o.blocked = 0 AND COALESCE(o.from_date, o.to_date) IS NOT NULL AND ' . $condition . ' AND ' . $yearExpression . ' BETWEEN ? AND ?';
        $rows = $db->query('SELECT o.' . $column . ' AS taxon_id, gro.geographic_region_id, ' . $yearExpression . ' AS year, COUNT(*) AS occurrences_count, COUNT(DISTINCT NULLIF(UPPER(TRIM(o.grid_ref_2km)), "")) AS grid_square_count' . $base . ' GROUP BY o.' . $column . ', gro.geographic_region_id, ' . $yearExpression . ' UNION ALL SELECT o.' . $column . ' AS taxon_id, NULL AS geographic_region_id, ' . $yearExpression . ' AS year, COUNT(*) AS occurrences_count, COUNT(DISTINCT NULLIF(UPPER(TRIM(o.grid_ref_2km)), "")) AS grid_square_count' . $base . ' GROUP BY o.' . $column . ', ' . $yearExpression, [$startYear, $endYear, $startYear, $endYear])->getResultArray();

        return array_map(function (array $row) use ($buildId, $projection): array {
            $taxonId = (int) $row['taxon_id'];
            $region = $row['geographic_region_id'] === null ? 'global' : (string) $row['geographic_region_id'];
            $year = (int) $row['year'];
            return ['build_id' => $buildId, 'projection' => $projection, 'uuid' => $this->stableUuid($taxonId . '|' . $region . '|' . $year),
                'taxon_id' => $taxonId, 'geographic_region_id' => $row['geographic_region_id'] === null ? null : (int) $row['geographic_region_id'],
                'year' => $year, 'occurrences_count' => (int) $row['occurrences_count'], 'grid_square_count' => (int) $row['grid_square_count']];
        }, $rows);
    }

    /** @return array<int, int> */
    private function completedYears(int $historyYears): array
    {
        $currentYear = (int) date('Y');
        if ($historyYears > 0) {
            return range($currentYear - $historyYears, $currentYear - 1);
        }
        $db = db_connect();
        $row = $db->query('SELECT MIN(CAST(strftime("%Y", COALESCE(from_date, to_date)) AS INTEGER)) AS year FROM ' . $db->getPrefix() . 'occurrences WHERE deleted_at IS NULL AND blocked = 0 AND COALESCE(from_date, to_date) IS NOT NULL')->getRowArray();
        $firstYear = (int) ($row['year'] ?? 0);
        return $firstYear > 0 && $firstYear < $currentYear ? range($firstYear, $currentYear - 1) : [];
    }

    /** @return array<int, string> */
    private function reportingColumns(): array
    {
        $ranks = config('Import')->taxonRanks ?? [];
        $ranks = is_array($ranks) ? $ranks : explode(',', (string) $ranks);
        return array_values(array_filter(array_map(static fn ($rank): string => is_scalar($rank) ? trim((string) preg_replace('/[^a-z0-9]+/i', '_', strtolower(trim((string) $rank))), '_') . '_id' : '', $ranks)));
    }

    /** @return array<string, mixed>|null */
    private function state(): ?array
    {
        $checkpoint = model(\App\Models\ImportOffsetModel::class)->getCheckpoint(self::SOURCE_KEY);
        $state = $checkpoint === null ? null : json_decode($checkpoint, true);
        return is_array($state) ? $state : null;
    }

    /** @param array<string, mixed> $state */
    private function saveState(array $state): void
    {
        $offset = model(\App\Models\ImportOffsetModel::class);
        $offset->setCheckpoint(self::SOURCE_KEY, json_encode($state, JSON_THROW_ON_ERROR));
        $offset->setCompletion(self::SOURCE_KEY, false);
    }

    private function clearState(): void
    {
        $offset = model(\App\Models\ImportOffsetModel::class);
        $offset->setCheckpoint(self::SOURCE_KEY, null);
        $offset->setCompletion(self::SOURCE_KEY, true);
    }

    private function publish(string $buildId): void
    {
        $db = db_connect();
        $db->transStart();
        $db->table('taxon_year_stats')->emptyTable();
        $db->query('INSERT INTO ' . $db->getPrefix() . 'taxon_year_stats (uuid, taxon_id, geographic_region_id, year, occurrences_count, grid_square_count) SELECT uuid, taxon_id, geographic_region_id, year, occurrences_count, grid_square_count FROM ' . $db->getPrefix() . 'taxon_year_stats_build WHERE build_id = ?', [$buildId]);
        $db->transComplete();
    }

    private function stableUuid(string $seed): string
    {
        $hex = md5($seed);
        return sprintf('%s-%s-4%s-%s%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 13, 3), dechex((hexdec(substr($hex, 16, 1)) & 0x3) | 0x8), substr($hex, 17, 3), substr($hex, 20, 12));
    }
}
