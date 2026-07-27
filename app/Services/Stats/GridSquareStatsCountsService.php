<?php

namespace App\Services\Stats;

/**
 * Recomputes grid square stats counts from active occurrences.
 */
class GridSquareStatsCountsService
{
    /**
     * The threshold for species occurrence counts to be included in rarity score calculations.
     * @var int
     */
    private const RARITY_THRESHOLD = 100;

    /**
     * Recompute occurrences_count, species_count and rarity_score for all grid square stats rows.
     *
     * @return array<string, int|string>
     */
    public function run(bool $dryRun = false): array
    {
        $counts = [
            'status' => 'success',
            'fetched' => 0,
            'processed' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        try {
            // Instantiate import config to trigger taxon rank validation.
            config('Import');

            $db = db_connect();
            $prefix = $db->getPrefix();
            $activeOccurrenceWhere = 'o.deleted_at IS NULL
                    AND o.blocked = 0
                    AND o.grid_ref_2km IS NOT NULL
                    AND TRIM(o.grid_ref_2km) <> ""';

            $aggregates = $db->query(
                'SELECT
                    aggregates.square,
                    aggregates.geographic_region_id,
                    aggregates.occurrences_count,
                    aggregates.species_count,
                    COALESCE(rarity.rarity_score, 0) AS rarity_score
                FROM (
                    SELECT
                        UPPER(TRIM(o.grid_ref_2km)) AS square,
                        gro.geographic_region_id AS geographic_region_id,
                        COUNT(*) AS occurrences_count,
                        COUNT(DISTINCT t.species_id) AS species_count
                    FROM ' . $prefix . 'occurrences o
                    INNER JOIN ' . $prefix . 'geographic_regions_occurrences gro
                        ON gro.occurrence_id = o.id
                    INNER JOIN ' . $prefix . 'taxa t
                        ON t.id = o.taxon_id
                    WHERE ' . $activeOccurrenceWhere . '
                    GROUP BY UPPER(TRIM(o.grid_ref_2km)), gro.geographic_region_id
                ) aggregates
                LEFT JOIN (
                    SELECT
                        square_species.square,
                        square_species.geographic_region_id,
                        ROUND(SUM((100.0 / species_totals.total_records) * square_species.square_occurrences_count), 4) AS rarity_score
                    FROM (
                        SELECT
                            UPPER(TRIM(o.grid_ref_2km)) AS square,
                            gro.geographic_region_id AS geographic_region_id,
                            t.species_id AS species_id,
                            COUNT(*) AS square_occurrences_count
                        FROM ' . $prefix . 'occurrences o
                        INNER JOIN ' . $prefix . 'geographic_regions_occurrences gro
                            ON gro.occurrence_id = o.id
                        INNER JOIN ' . $prefix . 'taxa t
                            ON t.id = o.taxon_id
                        WHERE ' . $activeOccurrenceWhere . '
                            AND t.species_id IS NOT NULL
                        GROUP BY UPPER(TRIM(o.grid_ref_2km)), gro.geographic_region_id, t.species_id
                    ) square_species
                    INNER JOIN (
                        SELECT
                            t.species_id AS species_id,
                            COUNT(*) AS total_records
                        FROM ' . $prefix . 'occurrences o
                        INNER JOIN ' . $prefix . 'taxa t
                            ON t.id = o.taxon_id
                        WHERE ' . $activeOccurrenceWhere . '
                            AND t.species_id IS NOT NULL
                        GROUP BY t.species_id
                        HAVING COUNT(*) <= ' . self::RARITY_THRESHOLD . '
                    ) species_totals
                        ON species_totals.species_id = square_species.species_id
                    GROUP BY square_species.square, square_species.geographic_region_id
                ) rarity
                    ON rarity.square = aggregates.square
                    AND rarity.geographic_region_id = aggregates.geographic_region_id'
            )->getResultArray();

            $counts['fetched'] = count($aggregates);

            if ($dryRun) {;
                $counts['processed'] = $counts['fetched'];

                return $counts;
            }

            $db->table('grid_square_stats')->update([
                'occurrences_count' => 0,
                'species_count' => 0,
                'rarity_score' => 0,
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
                    'rarity_score' => $this->formatRarityScore($aggregate['rarity_score'] ?? null),
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

    /**
     * Normalize the computed rarity score for decimal storage.
     *
     * @param mixed $value
     */
    private function formatRarityScore($value): string
    {
        if (! is_numeric($value)) {
            return '0.0000';
        }

        return number_format((float) $value, 4, '.', '');
    }
}
