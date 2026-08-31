<?php

namespace App\Services\Stats;

use Config\TaxonFrequencyTrend;

/**
 * Recomputes taxon_stats derived aggregates from active occurrences.
 */
class TaxonStatsService
{
    /**
     * Recompute taxon stats for global and regional scopes.
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
            $db = db_connect();
            $rows = $this->buildRows();

            $counts['fetched'] = count($rows);
            $counts['processed'] = $counts['fetched'];

            if ($dryRun) {
                return $counts;
            }

            $db->table('taxon_stats')->emptyTable();

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
     * Build recomputed taxon stats rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildRows(): array
    {
        $db = db_connect();
        $prefix = $db->getPrefix();
        $reportingColumns = $this->reportingColumns();
        $projectionColumns = $reportingColumns === [] ? '' : ",\n                    " . implode(",\n                    ", array_map(static fn (string $column): string => 'o.' . $column, $reportingColumns));
        $scopedOccurrences = $this->scopedOccurrenceSql($prefix, $reportingColumns);
        return $db->query(
            'WITH active_occurrences AS (
                SELECT
                    o.id AS occurrence_id,
                    o.taxon_id,
                    tr.is_reporting,
                    COALESCE(o.from_date, o.to_date) AS record_date,
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
                    AND o.blocked = 0
                    AND COALESCE(o.from_date, o.to_date) IS NOT NULL
            ),
            scoped_occurrences AS (' . $scopedOccurrences . '),
            aggregates AS (
                SELECT
                    so.taxon_id,
                    so.geographic_region_id,
                    COUNT(*) AS occurrences_count,
                    COUNT(DISTINCT so.grid_ref_2km) AS grid_square_count
                FROM scoped_occurrences so
                GROUP BY so.taxon_id, so.geographic_region_id
            ),
            first_rows AS (
                SELECT
                    ranked.taxon_id,
                    ranked.geographic_region_id,
                    ranked.record_date,
                    ranked.recorded_by
                FROM (
                    SELECT
                        so.*,
                        ROW_NUMBER() OVER (
                            PARTITION BY so.taxon_id, so.geographic_region_id
                            ORDER BY so.record_date ASC, so.occurrence_id ASC
                        ) AS rn
                    FROM scoped_occurrences so
                ) ranked
                WHERE ranked.rn = 1
            ),
            last_rows AS (
                SELECT
                    ranked.taxon_id,
                    ranked.geographic_region_id,
                    ranked.record_date,
                    ranked.recorded_by
                FROM (
                    SELECT
                        so.*,
                        ROW_NUMBER() OVER (
                            PARTITION BY so.taxon_id, so.geographic_region_id
                            ORDER BY so.record_date DESC, so.occurrence_id DESC
                        ) AS rn
                    FROM scoped_occurrences so
                ) ranked
                WHERE ranked.rn = 1
            ),
            first_verified_rows AS (
                SELECT
                    ranked.taxon_id,
                    ranked.geographic_region_id,
                    ranked.record_date,
                    ranked.recorded_by
                FROM (
                    SELECT
                        so.*,
                        ROW_NUMBER() OVER (
                            PARTITION BY so.taxon_id, so.geographic_region_id
                            ORDER BY so.record_date ASC, so.occurrence_id ASC
                        ) AS rn
                    FROM scoped_occurrences so
                    WHERE so.identification_verification_status LIKE "V%"
                ) ranked
                WHERE ranked.rn = 1
            ),
            last_verified_rows AS (
                SELECT
                    ranked.taxon_id,
                    ranked.geographic_region_id,
                    ranked.record_date,
                    ranked.recorded_by
                FROM (
                    SELECT
                        so.*,
                        ROW_NUMBER() OVER (
                            PARTITION BY so.taxon_id, so.geographic_region_id
                            ORDER BY so.record_date DESC, so.occurrence_id DESC
                        ) AS rn
                    FROM scoped_occurrences so
                    WHERE so.identification_verification_status LIKE "V%"
                ) ranked
                WHERE ranked.rn = 1
            )
            SELECT
                a.taxon_id,
                a.geographic_region_id,
                a.occurrences_count,
                a.grid_square_count,
                fr.record_date AS first_record_date,
                lr.record_date AS last_record_date,
                fr.recorded_by AS first_recorder,
                lr.recorded_by AS last_recorder,
                COALESCE(fvr.record_date, fr.record_date) AS first_verified_record_date,
                COALESCE(lvr.record_date, lr.record_date) AS last_verified_record_date,
                COALESCE(fvr.recorded_by, fr.recorded_by) AS first_verified_recorder,
                COALESCE(lvr.recorded_by, lr.recorded_by) AS last_verified_recorder
            FROM aggregates a
            INNER JOIN first_rows fr
                ON fr.taxon_id = a.taxon_id
                AND (
                    fr.geographic_region_id = a.geographic_region_id
                    OR (fr.geographic_region_id IS NULL AND a.geographic_region_id IS NULL)
                )
            INNER JOIN last_rows lr
                ON lr.taxon_id = a.taxon_id
                AND (
                    lr.geographic_region_id = a.geographic_region_id
                    OR (lr.geographic_region_id IS NULL AND a.geographic_region_id IS NULL)
                )
            LEFT JOIN first_verified_rows fvr
                ON fvr.taxon_id = a.taxon_id
                AND (
                    fvr.geographic_region_id = a.geographic_region_id
                    OR (fvr.geographic_region_id IS NULL AND a.geographic_region_id IS NULL)
                )
            LEFT JOIN last_verified_rows lvr
                ON lvr.taxon_id = a.taxon_id
                AND (
                    lvr.geographic_region_id = a.geographic_region_id
                    OR (lvr.geographic_region_id IS NULL AND a.geographic_region_id IS NULL)
                )'
        )->getResultArray();
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
     * Calculate frequency trends for species in each global or regional scope.
     *
    * @return array<string, array{score: int|null, state: string}> Trend results keyed by taxon and region.
     */
    private function frequencyTrends(): array
    {
        $db = db_connect();
        $prefix = $db->getPrefix();
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
            ORDER BY tys.taxon_id, tys.geographic_region_id, tys.year'
        )->getResultArray();

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

        $config = config(TaxonFrequencyTrend::class);
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

                foreach ($scope['years'] as $year => $values) {
                    $squareSeries[(int) $year] = (int) $values['grid_square_count'];
                    $occurrenceSeries[(int) $year] = (int) $values['occurrences_count'];
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
    private function scopedOccurrenceSql(string $prefix, array $columns): string
    {
        $exactCondition = 'ao.is_reporting = 0';
        $selects = [
            'SELECT ao.occurrence_id, ao.taxon_id, gro.geographic_region_id,
                ao.record_date, ao.grid_ref_2km, ao.recorded_by,
                ao.identification_verification_status
             FROM active_occurrences ao
             INNER JOIN ' . $prefix . 'geographic_regions_occurrences gro
                ON gro.occurrence_id = ao.occurrence_id
             WHERE ' . $exactCondition,
            'SELECT ao.occurrence_id, ao.taxon_id, NULL AS geographic_region_id,
                ao.record_date, ao.grid_ref_2km, ao.recorded_by,
                ao.identification_verification_status
             FROM active_occurrences ao
             WHERE ' . $exactCondition,
        ];

        foreach ($columns as $column) {
            $selects[] = 'SELECT ao.occurrence_id, ao.' . $column . ' AS taxon_id,
                gro.geographic_region_id, ao.record_date, ao.grid_ref_2km,
                ao.recorded_by, ao.identification_verification_status
             FROM active_occurrences ao
             INNER JOIN ' . $prefix . 'geographic_regions_occurrences gro
                ON gro.occurrence_id = ao.occurrence_id
             WHERE ao.' . $column . ' IS NOT NULL';
            $selects[] = 'SELECT ao.occurrence_id, ao.' . $column . ' AS taxon_id,
                NULL AS geographic_region_id, ao.record_date, ao.grid_ref_2km,
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
     *
     * @return void
     */
    private function insertRows(array $rows): void
    {
        $db = db_connect();
        $payload = [];
        $frequencyTrends = $this->frequencyTrends();

        foreach ($rows as $row) {
            $taxonId = (int) ($row['taxon_id'] ?? 0);
            $geographicRegionId = $this->nullableInt($row['geographic_region_id'] ?? null);

            if ($taxonId <= 0) {
                continue;
            }

            $payload[] = [
                'uuid' => $this->stableUuid($taxonId . '|' . ($geographicRegionId ?? 'global')),
                'taxon_id' => $taxonId,
                'geographic_region_id' => $geographicRegionId,
                'frequency_trend' => $frequencyTrends[$this->scopeKey($taxonId, $geographicRegionId)]['score'] ?? null,
                'frequency_trend_state' => $frequencyTrends[$this->scopeKey($taxonId, $geographicRegionId)]['state'] ?? null,
                'occurrences_count' => max(0, (int) ($row['occurrences_count'] ?? 0)),
                'grid_square_count' => max(0, (int) ($row['grid_square_count'] ?? 0)),
                'first_record_date' => (string) ($row['first_record_date'] ?? ''),
                'last_record_date' => (string) ($row['last_record_date'] ?? ''),
                'first_recorder' => substr((string) ($row['first_recorder'] ?? ''), 0, 255),
                'last_recorder' => substr((string) ($row['last_recorder'] ?? ''), 0, 255),
                'first_verified_record_date' => (string) ($row['first_verified_record_date'] ?? ''),
                'last_verified_record_date' => (string) ($row['last_verified_record_date'] ?? ''),
                'first_verified_recorder' => substr((string) ($row['first_verified_recorder'] ?? ''), 0, 255),
                'last_verified_recorder' => substr((string) ($row['last_verified_recorder'] ?? ''), 0, 255),
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
