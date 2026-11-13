<?php

namespace App\Services\Stats;

use CodeIgniter\I18n\Time;
use Config\Rarity;
use InvalidArgumentException;

/**
 * Recomputes taxon rarity categories from recent active occurrences of non-excluded species.
 */
class TaxonRarityService
{
    /**
     * Clear stored rarity categories before a complete recalculation.
     *
     * @return void
     */
    public function resetFullRebuild(): void
    {
        db_connect()->table('taxa')->update(['rarity_category' => null]);

        $offset = model(\App\Models\ImportOffsetModel::class);
        $offset->setCheckpoint('derived-stats:taxon_rarity', null);
        $offset->setCompletion('derived-stats:taxon_rarity', false);
    }

    /**
     * Recompute rarity_category for taxa grouped by rarity_group_name.
     *
     * Excluded species do not influence peer rankings and have their categories cleared.
     * Removing an exclusion lets the species rejoin its group on the next recalculation.
     * Species without active occurrences in the configured history window have null categories and do not influence
     * either metric's population. Fetched counts include these species for cleanup.
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
            $today = Time::now();
            $currentYear = (int) $today->format('Y');

            if ($config->historyYears < 1 || $config->historyYears > $currentYear) {
                throw new InvalidArgumentException('rarity.historyYears must be between 1 and the current calendar year.');
            }

            $firstDate = sprintf('%04d-01-01', $currentYear - $config->historyYears + 1);

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
                    AND o.from_date >= ?
                    AND o.from_date <= ?
                WHERE t.deleted_at IS NULL
                    AND t.blocked = 0
                    AND t.species_id = t.id
                    AND t.exclude_from_rarity_calculations = 0
                    AND t.rarity_group_name IS NOT NULL
                    AND TRIM(t.rarity_group_name) <> ""
                GROUP BY t.id, t.taxon_identifier, t.rarity_group_name, t.rarity_category',
                [$firstDate, $today->toDateString()],
            )->getResultArray();

            $excludedRows = $db->table('taxa')->select('id')
                ->where('exclude_from_rarity_calculations', 1)
                ->where('rarity_category IS NOT NULL', null, false)
                ->get()->getResultArray();
            $counts['fetched'] = count($rows) + count($excludedRows);
            $counts['processed'] = $counts['fetched'];
            $counts['updated'] = count($excludedRows);

            if ($rows === []) {
                if (! $dryRun) {
                    $db->table('taxa')->update(['rarity_category' => null]);
                }

                return $counts;
            }

            $updates = [];

            if (! $dryRun) {
                foreach ($excludedRows as $row) {
                    $updates[] = ['id' => (int) $row['id'], 'rarity_category' => null];
                }
            }

            $recordedRows = [];

            foreach ($rows as $row) {
                if ((int) $row['occurrence_count'] > 0) {
                    $recordedRows[] = $row;
                    continue;
                }

                if ($this->nullableInt($row['rarity_category'] ?? null) === null) {
                    $counts['not changed']++;
                    continue;
                }

                if (! $dryRun) {
                    $updates[] = ['id' => (int) $row['id'], 'rarity_category' => null];
                }

                $counts['updated']++;
            }

            $groupedRows = $this->groupRowsByRarityGroup($recordedRows);

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
     * Map weighted metric percentiles directly to fixed 20-point rarity bands.
     *
     * Both metrics use the same scale so their distinct-value counts cannot skew
     * the configured weights. Equal scores remain together without a second ranking.
     *
     * @param array<int, array<string, mixed>> $rows Group rows.
     * @param Rarity                            $config Rarity configuration.
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws InvalidArgumentException When weights are non-finite, negative or total zero.
     */
    private function computeGroupCategories(array $rows, Rarity $config): array
    {
        $gridSquareWeight = (float) $config->gridSquareWeight;
        $occurrenceWeight = (float) $config->occurrenceWeight;
        $totalWeight = $gridSquareWeight + $occurrenceWeight;

        if (! is_finite($gridSquareWeight) || ! is_finite($occurrenceWeight)
            || $gridSquareWeight < 0 || $occurrenceWeight < 0
            || ! is_finite($totalWeight) || $totalWeight <= 0) {
            throw new InvalidArgumentException('Rarity weights must be finite, non-negative and have a positive finite total.');
        }

        $squarePercentiles = $this->percentileRanks($rows, 'grid_square_count');
        $occurrencePercentiles = $this->percentileRanks($rows, 'occurrence_count');

        foreach ($rows as $index => $row) {
            $taxonId = (int) $row['id'];
            $score = ($squarePercentiles[$taxonId] * ($gridSquareWeight / $totalWeight))
                + ($occurrencePercentiles[$taxonId] * ($occurrenceWeight / $totalWeight));
            // Prevent floating-point noise moving an exact band boundary into the lower band.
            $score = round($score, 12);

            $rows[$index]['final_score'] = $score;
            $rows[$index]['computed_rarity_category'] = min(5, (int) floor($score / 20) + 1);
        }

        return $rows;
    }

    /**
     * Compute SQL-style percent ranks, retaining rank gaps for tied metric values.
     *
     * Each score is 100 times the number of species with a lower value divided
     * by group size minus one. A singleton scores zero, preserving category 1.
     * Recorded species with zero mapped squares remain eligible for both metrics.
     *
     * @param array<int, array<string, mixed>> $rows Group rows.
     * @param string                            $metric Metric column name.
     *
     * @return array<int, float> Percentile scores keyed by taxon ID.
     */
    private function percentileRanks(array $rows, string $metric): array
    {
        $values = [];

        foreach ($rows as $row) {
            $values[] = $this->nullableInt($row[$metric] ?? null) ?? 0;
        }

        sort($values, SORT_NUMERIC);
        $groupSize = count($values);
        $rankByValue = [];

        foreach ($values as $index => $value) {
            if (! array_key_exists($value, $rankByValue)) {
                $rankByValue[$value] = $groupSize <= 1 ? 0.0 : 100 * $index / ($groupSize - 1);
            }
        }

        $ranks = [];

        foreach ($rows as $row) {
            $taxonId = (int) $row['id'];
            $value = $this->nullableInt($row[$metric] ?? null) ?? 0;
            $ranks[$taxonId] = $rankByValue[$value];
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