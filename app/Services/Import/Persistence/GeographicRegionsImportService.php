<?php

namespace App\Services\Import\Persistence;

use CodeIgniter\Database\RawSql;

/**
 * Persists normalized geographic region rows.
 */
class GeographicRegionsImportService implements EntityImportServiceInterface
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
        $dataSourcesTable = $db->table('data_sources');
        $cachedDataSourceIds = [];

        foreach ($rows as $row) {
            try {
                $higherGeographyIdentifier = (string) ($row['higher_geography_identifier'] ?? '');
                $higherGeography = trim((string) ($row['higher_geography'] ?? ''));
                $locationType = trim((string) ($row['location_type'] ?? ''));
                $dataSourceAbbr = strtoupper(trim((string) ($row['data_source_abbr'] ?? 'IREC')));
                $footprintGeometry = $this->nullableString($row['footprint_geometry'] ?? $row['polygon_geometry'] ?? null, 65535);

                if ($higherGeographyIdentifier <= 0 || $higherGeography === '' || $locationType === '') {
                    log_message('debug', 'Geographic region row skipped due to missing required fields: ' . json_encode($row));
                    $counts['skipped']++;
                    $counts['processed']++;
                    continue;
                }

                if (! array_key_exists($dataSourceAbbr, $cachedDataSourceIds)) {
                    $dataSource = $dataSourcesTable->where('abbr', $dataSourceAbbr)->get()->getRowArray();
                    $cachedDataSourceIds[$dataSourceAbbr] = $dataSource === null ? 0 : (int) $dataSource['id'];
                }

                $dataSourceId = (int) $cachedDataSourceIds[$dataSourceAbbr];

                if ($dataSourceId <= 0) {
                    $counts['skipped']++;
                    $counts['processed']++;
                    continue;
                }

                $payload = [
                    'higher_geography_identifier' => $higherGeographyIdentifier,
                    'higher_geography' => substr($higherGeography, 0, 100),
                    'location_type' => substr($locationType, 0, 100),
                    'footprint_geometry' => $this->databaseGeometryValue($db, $footprintGeometry),
                    'data_source_id' => $dataSourceId,
                    'deleted_at' => null,
                ];

                $existing = $db->table('geographic_regions')
                    ->where('higher_geography_identifier', $higherGeographyIdentifier)
                    ->get()
                    ->getRowArray();

                if ($existing === null) {
                    $counts['inserted']++;

                    if (! $dryRun) {
                        $db->table('geographic_regions')->insert($payload);
                    }

                    $counts['processed']++;
                    continue;
                }

                $counts['updated']++;

                if (! $dryRun) {
                    $db->table('geographic_regions')->where('id', $existing['id'])->update($payload);
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
    private function nullableString($value, int $maxLength): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '') {
            return null;
        }

        return substr($string, 0, $maxLength);
    }

    /**
     * Convert a stored polygon string into the correct database value.
     *
     * @param object $db
     * @param string|null $geometry
     */
    private function databaseGeometryValue(object $db, ?string $geometry): mixed
    {
        if ($geometry === null) {
            return null;
        }

        if (strtoupper((string) ($db->DBDriver ?? '')) === 'SQLITE3') {
            return $geometry;
        }

        return new RawSql('ST_GeomFromText(' . $db->escape($geometry) . ')');
    }
}