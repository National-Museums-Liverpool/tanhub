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

        $activeOccurrences = $db->query(
            'SELECT
                o.id AS occurrence_id,
                o.taxon_id,
                COALESCE(o.from_date, o.to_date) AS record_date,
                CASE
                    WHEN o.grid_ref_2km IS NULL OR TRIM(o.grid_ref_2km) = "" THEN NULL
                    ELSE UPPER(TRIM(o.grid_ref_2km))
                END AS grid_ref_2km
            FROM ' . $prefix . 'occurrences o
            INNER JOIN ' . $prefix . 'taxa t
                ON t.id = o.taxon_id
                AND t.deleted_at IS NULL
                AND t.blocked = 0
            WHERE o.deleted_at IS NULL
                AND o.blocked = 0
                AND COALESCE(o.from_date, o.to_date) IS NOT NULL'
        )->getResultArray();

        if ($activeOccurrences === []) {
            return [];
        }

        $regions = $db->table('geographic_regions_occurrences')
            ->select(['occurrence_id', 'geographic_region_id'])
            ->get()
            ->getResultArray();

        $regionsByOccurrence = [];

        foreach ($regions as $row) {
            $occurrenceId = (int) ($row['occurrence_id'] ?? 0);
            $regionId = (int) ($row['geographic_region_id'] ?? 0);

            if ($occurrenceId <= 0 || $regionId <= 0) {
                continue;
            }

            $regionsByOccurrence[$occurrenceId][] = $regionId;
        }

        $currentYear = (int) date('Y');
        $minimumYear = $currentYear - 9;
        $aggregates = [];

        foreach ($activeOccurrences as $row) {
            $occurrenceId = (int) ($row['occurrence_id'] ?? 0);
            $taxonId = (int) ($row['taxon_id'] ?? 0);
            $year = $this->extractYear((string) ($row['record_date'] ?? ''));
            $square = (string) ($row['grid_ref_2km'] ?? '');

            if ($occurrenceId <= 0 || $taxonId <= 0 || $year === null) {
                continue;
            }

            if ($year < $minimumYear || $year > $currentYear) {
                continue;
            }

            $scopeRegionIds = $regionsByOccurrence[$occurrenceId] ?? [];

            // Add a global scope row for every occurrence.
            $scopeRegionIds[] = null;

            foreach ($scopeRegionIds as $regionId) {
                $key = $taxonId . '|' . ($regionId === null ? 'global' : (string) $regionId) . '|' . $year;

                if (! isset($aggregates[$key])) {
                    $aggregates[$key] = [
                        'taxon_id' => $taxonId,
                        'geographic_region_id' => $regionId,
                        'year' => $year,
                        'occurrences_count' => 0,
                        'grid_squares' => [],
                    ];
                }

                $aggregates[$key]['occurrences_count']++;

                if ($square !== '') {
                    $aggregates[$key]['grid_squares'][$square] = true;
                }
            }
        }

        $rows = [];

        foreach ($aggregates as $aggregate) {
            $taxonId = (int) $aggregate['taxon_id'];
            $regionId = $this->nullableInt($aggregate['geographic_region_id']);
            $year = (int) $aggregate['year'];
            $seedRegion = $regionId === null ? 'global' : (string) $regionId;

            $rows[] = [
                'uuid' => $this->stableUuid($taxonId . '|' . $seedRegion . '|' . $year),
                'taxon_id' => $taxonId,
                'geographic_region_id' => $regionId,
                'year' => $year,
                'occurrences_count' => max(0, (int) $aggregate['occurrences_count']),
                'grid_square_count' => count($aggregate['grid_squares']),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            $taxonCompare = ((int) $left['taxon_id']) <=> ((int) $right['taxon_id']);

            if ($taxonCompare !== 0) {
                return $taxonCompare;
            }

            $regionLeft = $left['geographic_region_id'] === null ? -1 : (int) $left['geographic_region_id'];
            $regionRight = $right['geographic_region_id'] === null ? -1 : (int) $right['geographic_region_id'];
            $regionCompare = $regionLeft <=> $regionRight;

            if ($regionCompare !== 0) {
                return $regionCompare;
            }

            return ((int) $left['year']) <=> ((int) $right['year']);
        });

        return $rows;
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
     * Extract year component from a date string.
     *
     * @param string $value Date value.
     *
     * @return int|null
     */
    private function extractYear(string $value): ?int
    {
        $value = trim($value);

        if ($value === '' || strlen($value) < 4) {
            return null;
        }

        $year = substr($value, 0, 4);

        if (! ctype_digit($year)) {
            return null;
        }

        return (int) $year;
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
