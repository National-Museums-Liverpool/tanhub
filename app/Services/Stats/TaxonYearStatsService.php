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
     * Recompute one bounded taxon year stats batch.
     *
     * @param bool $dryRun Whether persistence is disabled for this run.
     *
     * @return array<string, int|string|bool> Batch result and continuation state.
     */
    public function run(bool $dryRun = false): array
    {
        $counts = ['status' => 'success', 'fetched' => 0, 'processed' => 0, 'inserted' => 0,
            'updated' => 0, 'not changed' => 0, 'skipped' => 0, 'errors' => 0, 'has_more' => false];

        try {
            $db = db_connect();
            $config = config(TaxonYearStats::class);
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
