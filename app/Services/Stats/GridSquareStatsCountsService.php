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
     * Counts are scoped to "active" occurrences (not soft-deleted, not
     * `blocked`, with a non-empty `grid_ref_2km`) joined to their assigned
     * geographic region(s). The rarity score treats a species as "rare" when
     * it has 100 or fewer active gridded occurrences overall
     * ({@see self::RARITY_THRESHOLD}); for each such qualifying occurrence,
     * `100 / <species total active gridded records>` is added to the score
     * of the grid square/region where that occurrence falls, so widely
     * scattered rare species contribute fractional amounts to many squares.
     * On a non-dry-run, all `grid_square_stats` counts are first reset to
     * zero before the freshly computed aggregates are written back.
     *
     * @param bool $dryRun When true, compute counts without writing changes.
     *
     * @return array<string, int|string> Result summary: `status` (`success`|`failed`),
     *                                   `fetched`, `processed`, `updated`, `skipped`, `errors`.
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
                    AND TRIM(o.grid_ref_2km) <> ""
                    AND t.deleted_at IS NULL
                    AND t.blocked = 0';

            $aggregates = $db->query(
                // Outer query: for every (square, geographic_region_id) pair, combine the
                // plain occurrence/species counts ("aggregates") with the rarity score
                // ("rarity"), defaulting to 0 when no rare species fall in that square.
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
                    -- rarity: for each square, sum (100 / species total active gridded
                    -- records) across every qualifying rare species occurrence that
                    -- falls in that square, so a rare species contribution is split
                    -- proportionally across every square/region it appears in.
                    SELECT
                        square_species.square,
                        square_species.geographic_region_id,
                        ROUND(SUM((100.0 / species_totals.total_records) * square_species.square_occurrences_count), 4) AS rarity_score
                    FROM (
                        -- square_species: active gridded occurrence count per
                        -- (square, region, species).
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
                        -- species_totals: total active gridded occurrences per species,
                        -- restricted to species with RARITY_THRESHOLD or fewer records
                        -- (i.e. the "rare" species that contribute to rarity_score).
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

            $lookupSquares = [];
            $lookupRegionIds = [];

            foreach ($aggregates as $aggregate) {
                $square = strtoupper(trim((string) ($aggregate['square'] ?? '')));
                $geographicRegionId = (int) ($aggregate['geographic_region_id'] ?? 0);

                if ($square !== '' && $geographicRegionId > 0) {
                    $lookupSquares[$square] = true;
                    $lookupRegionIds[$geographicRegionId] = true;
                }
            }

            $existingRows = [];

            if ($lookupSquares !== [] && $lookupRegionIds !== []) {
                foreach ($db->table('grid_square_stats')
                    ->select(['id', 'square', 'geographic_region_id'])
                    ->whereIn('square', array_keys($lookupSquares))
                    ->whereIn('geographic_region_id', array_keys($lookupRegionIds))
                    ->get()
                    ->getResultArray() as $existingRow) {
                    $existingRows[strtoupper((string) $existingRow['square']) . '|' . (int) $existingRow['geographic_region_id']] = $existingRow;
                }
            }

            $updates = [];

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

                $existing = $existingRows[$square . '|' . $geographicRegionId] ?? null;

                if ($existing === null) {
                    log_message('warning', 'Grid square stats counts skipped for missing grid square row: square=' . $square . ', geographic_region_id=' . $geographicRegionId);
                    $counts['skipped']++;
                    $counts['processed']++;
                    continue;
                }

                $updates[] = ['id' => (int) $existing['id']] + $update;

                $counts['updated']++;
                $counts['processed']++;
            }

            if ($updates !== []) {
                $db->table('grid_square_stats')->updateBatch($updates, 'id');
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
