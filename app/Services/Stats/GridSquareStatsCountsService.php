<?php

namespace App\Services\Stats;

/**
 * Recomputes grid square stats counts from active occurrences.
 */
class GridSquareStatsCountsService
{
    /**
     * Recompute occurrences_count and species_count for all grid square stats rows.
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
            'skipped' => 0,
            'errors' => 0,
        ];

        try {
            // Instantiate import config to trigger taxon rank validation.
            config('Import');

            $db = db_connect();
            $prefix = $db->getPrefix();

            $aggregates = $db->query(
                'SELECT
                    o.grid_ref_2km AS square,
                    gro.geographic_region_id AS geographic_region_id,
                    COUNT(*) AS occurrences_count,
                    COUNT(DISTINCT t.species_id) AS species_count
                FROM ' . $prefix . 'occurrences o
                INNER JOIN ' . $prefix . 'geographic_regions_occurrences gro
                    ON gro.occurrence_id = o.id
                INNER JOIN ' . $prefix . 'taxa t
                    ON t.id = o.taxon_id
                WHERE o.deleted_at IS NULL
                    AND o.blocked = 0
                    AND o.grid_ref_2km IS NOT NULL
                    AND TRIM(o.grid_ref_2km) <> ""
                GROUP BY o.grid_ref_2km, gro.geographic_region_id'
            )->getResultArray();

            $counts['fetched'] = count($aggregates);

            if ($dryRun) {
                $counts['processed'] = $counts['fetched'];

                return $counts;
            }

            $db->table('grid_square_stats')->update([
                'occurrences_count' => 0,
                'species_count' => 0,
            ]);

            foreach ($aggregates as $aggregate) {
                $square = strtoupper(trim((string) ($aggregate['square'] ?? '')));
                $geographicRegionId = (int) ($aggregate['geographic_region_id'] ?? 0);

                if ($square === '' || $geographicRegionId <= 0) {
                    $counts['skipped']++;
                    $counts['processed']++;
                    continue;
                }

                $update = [
                    'occurrences_count' => max(0, (int) ($aggregate['occurrences_count'] ?? 0)),
                    'species_count' => max(0, (int) ($aggregate['species_count'] ?? 0)),
                ];

                $existing = $db->table('grid_square_stats')
                    ->select('id')
                    ->where('square', $square)
                    ->where('geographic_region_id', $geographicRegionId)
                    ->get()
                    ->getRowArray();

                if ($existing === null) {
                    log_message('warning', 'Grid square stats counts skipped for missing grid square row: square=' . $square . ', geographic_region_id=' . $geographicRegionId);
                    $counts['skipped']++;
                    $counts['processed']++;
                    continue;
                }

                $db->table('grid_square_stats')
                    ->where('id', (int) $existing['id'])
                    ->update($update);

                $counts['updated']++;
                $counts['processed']++;
            }
        } catch (\Throwable $exception) {
            log_message('error', $exception->getMessage());
            $counts['status'] = 'failed';
            $counts['errors']++;
        }

        return $counts;
    }
}
