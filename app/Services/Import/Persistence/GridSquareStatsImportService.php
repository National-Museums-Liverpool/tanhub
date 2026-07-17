<?php

namespace App\Services\Import\Persistence;

/**
 * Persists normalized grid square stats rows.
 */
class GridSquareStatsImportService implements EntityImportServiceInterface
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
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
                $locationIdentifier = $this->nullableInt($row['location_id'] ?? null) ?? $this->nullableInt($row['location_code'] ?? null);
                $square = strtoupper(trim((string) ($row['square'] ?? '')));
                $easting = $this->nullableInt($row['centre_easting'] ?? null);
                $northing = $this->nullableInt($row['centre_northing'] ?? null);
                $lat = $this->nullableDecimal($row['centre_lat'] ?? null);
                $lon = $this->nullableDecimal($row['centre_lon'] ?? null);
                $partial = $this->toFlag($row['partial'] ?? 0);

                if ($locationIdentifier === null || $square === '' || $easting === null || $northing === null || $lat === null || $lon === null) {
                    log_message('warning', 'Grid square stats row skipped due to missing required fields: ' . json_encode($row));
                    log_message('warning', 'Fields: ' . json_encode([
                        'location_id' => $locationIdentifier,
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

                $cacheKey = (string) $locationIdentifier;

                if (! array_key_exists($cacheKey, $cachedGeographicRegionIds)) {
                    $geographicRegion = $db->table('geographic_regions')
                        ->select('id')
                        ->where('higher_geography_identifier', $locationIdentifier)
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
                    'uuid' => $this->stableUuid((string) $locationIdentifier . '|' . $square),
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
     * @param mixed $value
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
     * @param mixed $value
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
     * @param mixed $value
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