<?php

namespace App\Services\Stats;

use Config\Rarity;

/**
 * Recomputes taxon rarity categories from active occurrence coverage.
 */
class TaxonRarityService
{
    /**
     * Recompute rarity_category for taxa grouped by rarity_group_name.
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
            'updated' => 0,
            'not changed' => 0,
            'errors' => 0,
        ];

        try {
            $db = db_connect();
            $prefix = $db->getPrefix();
            $config = config(Rarity::class);

            $rows = $db->query(
                'SELECT
                    t.id,
                    t.taxon_identifier,
                    t.rarity_group_name,
                    t.rarity_category,
                    COUNT(o.id) AS occurrence_count,
                    COUNT(DISTINCT CASE
                        WHEN o.grid_ref_2km IS NOT NULL AND TRIM(o.grid_ref_2km) <> "" THEN UPPER(TRIM(o.grid_ref_2km))
                        ELSE NULL
                    END) AS grid_square_count
                FROM ' . $prefix . 'taxa t
                LEFT JOIN ' . $prefix . 'occurrences o
                    ON o.species_id = t.id
                    AND o.deleted_at IS NULL
                    AND o.blocked = 0
                WHERE t.deleted_at IS NULL
                    AND t.blocked = 0
                    AND t.species_id = t.id
                    AND t.rarity_group_name IS NOT NULL
                    AND TRIM(t.rarity_group_name) <> ""
                GROUP BY t.id, t.taxon_identifier, t.rarity_group_name, t.rarity_category'
            )->getResultArray();

            $counts['fetched'] = count($rows);
            $counts['processed'] = count($rows);

            if ($rows === []) {
                if (! $dryRun) {
                    $db->table('taxa')->update(['rarity_category' => null]);
                }

                return $counts;
            }

            $groupedRows = $this->groupRowsByRarityGroup($rows);
            $updates = [];

            foreach ($groupedRows as $groupRows) {
                $computedRows = $this->computeGroupCategories($groupRows, $config);

                foreach ($computedRows as $row) {
                    $currentCategory = $this->nullableInt($row['rarity_category'] ?? null);
                    $newCategory = (int) $row['computed_rarity_category'];

                    if ($currentCategory === $newCategory) {
                        $counts['not changed']++;
                        continue;
                    }

                    if (! $dryRun) {
                        $updates[] = [
                            'id' => (int) $row['id'],
                            'rarity_category' => $newCategory,
                        ];
                    }

                    $counts['updated']++;
                }
            }

            if ($updates !== []) {
                $db->table('taxa')->updateBatch($updates, 'id');
            }

            if (! $dryRun) {
                $db->table('taxa')
                    ->where('species_id != id', null, false)
                    ->update(['rarity_category' => null]);
            }
        } catch (\Throwable $exception) {
            log_message('error', $exception->getMessage());
            $counts['status'] = 'failed';
            $counts['errors']++;
        }

        return $counts;
    }

    /**
     * Group aggregate rows by rarity group name.
     *
     * @param array<int, array<string, mixed>> $rows Aggregate rows.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupRowsByRarityGroup(array $rows): array
    {
        $groupedRows = [];

        foreach ($rows as $row) {
            $groupName = trim((string) ($row['rarity_group_name'] ?? ''));

            if ($groupName === '') {
                continue;
            }

            $groupedRows[$groupName][] = $row;
        }

        return $groupedRows;
    }

    /**
     * Compute weighted rarity categories for a single rarity group.
     *
     * @param array<int, array<string, mixed>> $rows Group rows.
     * @param Rarity                            $config Rarity configuration.
     *
     * @return array<int, array<string, mixed>>
     */
    private function computeGroupCategories(array $rows, Rarity $config): array
    {
        $squareRanks = $this->denseRanks($rows, 'grid_square_count');
        $occurrenceRanks = $this->denseRanks($rows, 'occurrence_count');
        $squareWeight = (float) $config->squareWeight;
        $occurrenceWeight = (float) $config->occurrenceWeight;

        foreach ($rows as $index => $row) {
            $taxonId = (int) $row['id'];
            $gridSquareRank = $squareRanks[$taxonId] ?? 1;
            $occurrenceRank = $occurrenceRanks[$taxonId] ?? 1;

            $rows[$index]['grid_square_rank'] = $gridSquareRank;
            $rows[$index]['occurrence_rank'] = $occurrenceRank;
            $rows[$index]['final_score'] = ($gridSquareRank * $squareWeight) + ($occurrenceRank * $occurrenceWeight);
        }

        usort($rows, static function (array $left, array $right): int {
            $scoreComparison = ((float) $left['final_score']) <=> ((float) $right['final_score']);

            if ($scoreComparison !== 0) {
                return $scoreComparison;
            }

            $squareComparison = ((int) $left['grid_square_rank']) <=> ((int) $right['grid_square_rank']);

            if ($squareComparison !== 0) {
                return $squareComparison;
            }

            $occurrenceComparison = ((int) $left['occurrence_rank']) <=> ((int) $right['occurrence_rank']);

            if ($occurrenceComparison !== 0) {
                return $occurrenceComparison;
            }

            return strcmp((string) ($left['taxon_identifier'] ?? ''), (string) ($right['taxon_identifier'] ?? ''));
        });

        $groupSize = count($rows);

        foreach ($rows as $index => $row) {
            $rows[$index]['computed_rarity_category'] = $this->categoryForPosition($index, $groupSize);
        }

        return $rows;
    }

    /**
     * Map a sorted position in a group onto the 1-5 rarity category scale.
     *
     * @param int $index Zero-based index in ascending rarity order.
     * @param int $groupSize Number of taxa in the rarity group.
     *
     * @return int
     */
    private function categoryForPosition(int $index, int $groupSize): int
    {
        if ($groupSize <= 1) {
            return 1;
        }

        $scaledPosition = round(($index / ($groupSize - 1)) * 4);

        return max(1, min(5, (int) $scaledPosition + 1));
    }

    /**
     * Compute dense ranks for a metric within a rarity group.
     *
     * @param array<int, array<string, mixed>> $rows Group rows.
     * @param string                            $metric Metric column name.
     *
     * @return array<int, int>
     */
    private function denseRanks(array $rows, string $metric): array
    {
        $values = [];

        foreach ($rows as $row) {
            $values[] = $this->nullableInt($row[$metric] ?? null) ?? 0;
        }

        $uniqueValues = array_values(array_unique($values));
        sort($uniqueValues, SORT_NUMERIC);

        $rankByValue = [];

        foreach ($uniqueValues as $index => $value) {
            $rankByValue[(string) $value] = $index + 1;
        }

        $ranks = [];

        foreach ($rows as $row) {
            $taxonId = (int) $row['id'];
            $value = $this->nullableInt($row[$metric] ?? null) ?? 0;
            $ranks[$taxonId] = $rankByValue[(string) $value] ?? 1;
        }

        return $ranks;
    }

    /**
     * Normalize nullable integer values.
     *
     * @param mixed $value Raw value.
     *
     * @return int|null
     */
    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}