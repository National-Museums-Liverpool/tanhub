<?php

namespace App\Services\Stats;

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

        return $db->query(
            'WITH active_occurrences AS (
                SELECT
                    o.id AS occurrence_id,
                    o.taxon_id,
                    COALESCE(o.from_date, o.to_date) AS record_date,
                    CASE
                        WHEN o.grid_ref_2km IS NULL OR TRIM(o.grid_ref_2km) = "" THEN NULL
                        ELSE UPPER(TRIM(o.grid_ref_2km))
                    END AS grid_ref_2km,
                    COALESCE(TRIM(o.recorded_by), "") AS recorded_by,
                    COALESCE(o.identification_verification_status, "") AS identification_verification_status
                FROM ' . $prefix . 'occurrences o
                INNER JOIN ' . $prefix . 'taxa t
                    ON t.id = o.taxon_id
                    AND t.deleted_at IS NULL
                    AND t.blocked = 0
                WHERE o.deleted_at IS NULL
                    AND o.blocked = 0
                    AND COALESCE(o.from_date, o.to_date) IS NOT NULL
            ),
            scoped_occurrences AS (
                SELECT
                    ao.occurrence_id,
                    ao.taxon_id,
                    gro.geographic_region_id,
                    ao.record_date,
                    ao.grid_ref_2km,
                    ao.recorded_by,
                    ao.identification_verification_status
                FROM active_occurrences ao
                INNER JOIN ' . $prefix . 'geographic_regions_occurrences gro
                    ON gro.occurrence_id = ao.occurrence_id

                UNION ALL

                SELECT
                    ao.occurrence_id,
                    ao.taxon_id,
                    NULL AS geographic_region_id,
                    ao.record_date,
                    ao.grid_ref_2km,
                    ao.recorded_by,
                    ao.identification_verification_status
                FROM active_occurrences ao
            ),
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
