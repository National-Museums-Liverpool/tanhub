<?php

namespace App\Services\Stats;

/**
 * Recomputes taxon_year_stats derived aggregates from active occurrences.
 */
class TaxonYearStatsService
{
    /**
     * Recompute taxon year stats for global and regional scopes.
     *
     * @param bool $dryRun Whether persistence is disabled for this run.
     *
     * @return array<string, int|string>
     */
    public function run(bool $dryRun = false): array
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
        ];

        try {
            $rows = $this->buildRows();
            $counts['fetched'] = count($rows);
            $counts['processed'] = $counts['fetched'];

            if ($dryRun) {
                return $counts;
            }

            $db = db_connect();
            $db->table('taxon_year_stats')->emptyTable();

            if ($rows !== []) {
                $this->insertRows($rows);
                $counts['inserted'] = count($rows);
            }
        } catch (\Throwable $exception) {
            log_message('error', $exception->getMessage());
            $counts['status'] = 'failed';
            $counts['errors']++;
        }

        return $counts;
    }

    /**
     * Build recomputed taxon year stats rows.
     *
     * @return array<int, array<string, int|null|string>>
     */
    private function buildRows(): array
    {
        $db = db_connect();
        $prefix = $db->getPrefix();
        $currentYear = (int) date('Y');
        $minimumYear = $currentYear - 9;
        $minimumDate = $minimumYear . '-01-01';
        $nextYearDate = ($currentYear + 1) . '-01-01';
        $driver = strtoupper((string) ($db->DBDriver ?? ''));

        $yearExpression = match ($driver) {
            'SQLITE3' => "CAST(strftime('%Y', record_date) AS INTEGER)",
            'POSTGRE' => 'EXTRACT(YEAR FROM record_date)',
            default => 'YEAR(record_date)',
        };

        $rows = $db->query(
            'WITH active_occurrences AS (
                SELECT
                    o.id AS occurrence_id,
                    o.taxon_id,
                    COALESCE(o.from_date, o.to_date) AS record_date,
                    NULLIF(UPPER(TRIM(o.grid_ref_2km)), "") AS grid_ref_2km
                FROM ' . $prefix . 'occurrences o
                INNER JOIN ' . $prefix . 'taxa t
                    ON t.id = o.taxon_id
                    AND t.deleted_at IS NULL
                    AND t.blocked = 0
                WHERE o.deleted_at IS NULL
                    AND o.blocked = 0
                    AND COALESCE(o.from_date, o.to_date) >= ?
                    AND COALESCE(o.from_date, o.to_date) < ?
            ),
            scoped_occurrences AS (
                SELECT
                    ao.taxon_id,
                    gro.geographic_region_id,
                    ' . $yearExpression . ' AS year,
                    ao.grid_ref_2km
                FROM active_occurrences ao
                INNER JOIN ' . $prefix . 'geographic_regions_occurrences gro
                    ON gro.occurrence_id = ao.occurrence_id

                UNION ALL

                SELECT
                    ao.taxon_id,
                    NULL AS geographic_region_id,
                    ' . $yearExpression . ' AS year,
                    ao.grid_ref_2km
                FROM active_occurrences ao
            )
            SELECT
                taxon_id,
                geographic_region_id,
                year,
                COUNT(*) AS occurrences_count,
                COUNT(DISTINCT grid_ref_2km) AS grid_square_count
            FROM scoped_occurrences
            GROUP BY taxon_id, geographic_region_id, year
            ORDER BY taxon_id, geographic_region_id, year',
            [$minimumDate, $nextYearDate],
        )->getResultArray();

        $result = [];

        foreach ($rows as $row) {
            $taxonId = (int) ($row['taxon_id'] ?? 0);
            $regionId = $this->nullableInt($row['geographic_region_id'] ?? null);
            $year = (int) ($row['year'] ?? 0);

            if ($taxonId <= 0 || $year <= 0) {
                continue;
            }

            $seedRegion = $regionId === null ? 'global' : (string) $regionId;
            $result[] = [
                'uuid' => $this->stableUuid($taxonId . '|' . $seedRegion . '|' . $year),
                'taxon_id' => $taxonId,
                'geographic_region_id' => $regionId,
                'year' => $year,
                'occurrences_count' => max(0, (int) ($row['occurrences_count'] ?? 0)),
                'grid_square_count' => max(0, (int) ($row['grid_square_count'] ?? 0)),
            ];
        }

        return $result;
    }

    /**
     * Insert computed rows into taxon_year_stats.
     *
     * @param array<int, array<string, int|null|string>> $rows Computed rows.
     *
     * @return void
     */
    private function insertRows(array $rows): void
    {
        $db = db_connect();
        $chunks = array_chunk($rows, 500);

        foreach ($chunks as $chunk) {
            $db->table('taxon_year_stats')->insertBatch($chunk);
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

    /**
     * Build a deterministic UUID-like value from a seed string.
     *
     * @param string $seed Identifier seed.
     *
     * @return string
     */
    private function stableUuid(string $seed): string
    {
        $hex = md5($seed);

        return sprintf(
            '%s-%s-4%s-%s%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 13, 3),
            dechex((hexdec(substr($hex, 16, 1)) & 0x3) | 0x8),
            substr($hex, 17, 3),
            substr($hex, 20, 12),
        );
    }
}
