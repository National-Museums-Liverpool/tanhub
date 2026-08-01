<?php

namespace App\Services\Import\Persistence;

/**
 * Persists normalized grid square stats rows.
 *
 * Upserts each row into `grid_square_stats` keyed by the composite of
 * `square` (2km OSGB grid reference) and `geographic_region_id`, the latter
 * resolved by looking up `higher_geography_identifier` against
 * `geographic_regions`. This populates only the static grid geometry; actual
 * occurrence/species counts are computed later by
 * {@see \App\Services\Stats\GridSquareStatsCountsService}.
 */
class GridSquareStatsImportService implements EntityImportServiceInterface
{
    /**
     * Persist a batch of normalized grid square stats rows.
     *
     * Rows are skipped when required fields (`higher_geography_identifier`,
     * `square`, easting/northing, or lat/lon) are missing, or when the
     * `higher_geography_identifier` does not resolve to a known, non-deleted
     * `geographic_regions` row (region lookups are cached per-batch by
     * identifier). The upsert key is the pair (`square`, `geographic_region_id`);
     * a deterministic UUID is derived from
     * `higher_geography_identifier|square` via {@see self::stableUuid()} so
     * re-imports produce the same row identity.
     *
     * @param array<int, array<string, mixed>> $rows   Normalized grid square rows.
     *                                                  Expected keys: `higher_geography_identifier`,
     *                                                  `square`, `centre_easting`, `centre_northing`,
     *                                                  `centre_lat`, `centre_lon`, `partial`.
     * @param bool                             $dryRun When true, compute counts without
     *                                                  writing changes.
     *
     * @return array<string, int> Result counts: `fetched`, `processed`, `inserted`,
     *                            `updated`, `skipped`, `errors`.
     */
    public function import(array $rows, bool $dryRun = false): array
    {
        $counts = [
            'fetched' => count($rows),
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        if ($rows === []) {
            return $counts;
        }

        $db = db_connect();
        $cachedGeographicRegionIds = [];

        foreach ($rows as $row) {
            try {
                $higherGeographyIdentifier = trim((string) ($row['higher_geography_identifier'] ?? ''));
                $square = strtoupper(trim((string) ($row['square'] ?? '')));
                $easting = $this->nullableInt($row['centre_easting'] ?? null);
                $northing = $this->nullableInt($row['centre_northing'] ?? null);
                $lat = $this->nullableDecimal($row['centre_lat'] ?? null);
                $lon = $this->nullableDecimal($row['centre_lon'] ?? null);
                $partial = $this->toFlag($row['partial'] ?? 0);

                if ($higherGeographyIdentifier === null || $square === '' || $easting === null || $northing === null || $lat === null || $lon === null) {
                    log_message('warning', 'Grid square stats row skipped due to missing required fields: ' . json_encode($row));
                    log_message('warning', 'Fields: ' . json_encode([
                        'higher_geography_identifier' => $higherGeographyIdentifier,
                        'square' => $square,
                        'centre_easting' => $easting,
                        'centre_northing' => $northing,
                        'centre_lat' => $lat,
                        'centre_lon' => $lon,
                        'partial' => $partial,
                    ]));
                    $counts['skipped']++;
                    $counts['processed']++;
                    continue;
                }

                $cacheKey = (string) $higherGeographyIdentifier;

                if (! array_key_exists($cacheKey, $cachedGeographicRegionIds)) {
                    $geographicRegion = $db->table('geographic_regions')
                        ->select('id')
                        ->where('higher_geography_identifier', $higherGeographyIdentifier)
                        ->where('deleted_at', null)
                        ->get()
                        ->getRowArray();

                    $cachedGeographicRegionIds[$cacheKey] = $geographicRegion === null ? 0 : (int) $geographicRegion['id'];
                }

                $geographicRegionId = (int) $cachedGeographicRegionIds[$cacheKey];

                if ($geographicRegionId <= 0) {
                    log_message('warning', 'Grid square stats row skipped due to missing geographic region: ' . json_encode($row));
                    $counts['skipped']++;
                    $counts['processed']++;
                    continue;
                }

                $payload = [
                    'uuid' => $this->stableUuid((string) $higherGeographyIdentifier . '|' . $square),
                    'square' => substr($square, 0, 12),
                    'geographic_region_id' => $geographicRegionId,
                    'easting' => $easting,
                    'northing' => $northing,
                    'lon' => $lon,
                    'lat' => $lat,
                    'partial' => $partial,
                ];

                $existing = $db->table('grid_square_stats')
                    ->where('square', $payload['square'])
                    ->where('geographic_region_id', $geographicRegionId)
                    ->get()
                    ->getRowArray();

                if ($existing === null) {
                    $counts['inserted']++;

                    if (! $dryRun) {
                        $db->table('grid_square_stats')->insert($payload);
                    }

                    $counts['processed']++;
                    continue;
                }

                $counts['updated']++;

                if (! $dryRun) {
                    $db->table('grid_square_stats')->where('id', $existing['id'])->update($payload);
                }

                $counts['processed']++;
            } catch (\Throwable $exception) {
                log_message('error', $exception->getMessage());
                $counts['errors']++;
                break;
            }
        }

        return $counts;
    }

    /**
     * Coerce a scalar value into a trimmed nullable integer.
     *
     * @param mixed $value Raw value to coerce; non-numeric/non-scalar values become null.
     *
     * @return int|null Integer value, or null when empty/non-numeric.
     */
    private function nullableInt($value): ?int
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
     * Coerce a scalar value into a trimmed decimal string for storage.
     *
     * Kept as a string (rather than float) to avoid floating-point rounding
     * when writing to decimal database columns.
     *
     * @param mixed $value Raw value to coerce; non-scalar values become null.
     *
     * @return string|null Trimmed decimal string, or null when empty/non-scalar.
     */
    private function nullableDecimal($value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * Normalize a loosely-typed truthy/falsy value into a `0`/`1` flag.
     *
     * Accepts booleans, numeric values (`>0` is true), and common string
     * representations (`1`, `true`, `t`, `yes`, `y`, case-insensitive).
     * Anything else is treated as false.
     *
     * @param mixed $value Raw value to normalize.
     *
     * @return int `1` when truthy, otherwise `0`.
     */
    private function toFlag($value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            return ((int) $value) > 0 ? 1 : 0;
        }

        if (is_string($value)) {
            $normalised = strtolower(trim($value));

            if (in_array($normalised, ['1', 'true', 't', 'yes', 'y'], true)) {
                return 1;
            }
        }

        return 0;
    }

    /**
     * Build a deterministic UUID-like value from a seed string.
     *
     * Used so repeated imports of the same `higher_geography_identifier|square`
     * combination generate the same `uuid`, keeping row identity stable
     * across re-imports.
     *
     * @param string $seed Identifier seed, e.g. `<higher_geography_identifier>|<square>`.
     *
     * @return string Deterministic UUID-v4-shaped string derived from the seed.
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