<?php

namespace App\Services\Import\Persistence;

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
                $higherGeographyIdentifier = (int) ($row['higher_geography_identifier'] ?? 0);
                $higherGeography = trim((string) ($row['higher_geography'] ?? ''));
                $locationType = trim((string) ($row['location_type'] ?? ''));
                $dataSourceAbbr = strtoupper(trim((string) ($row['data_source_abbr'] ?? 'IREC')));

                if ($higherGeographyIdentifier <= 0 || $higherGeography === '' || $locationType === '') {
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
}