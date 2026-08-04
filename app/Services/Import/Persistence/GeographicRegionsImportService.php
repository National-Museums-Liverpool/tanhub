<?php

namespace App\Services\Import\Persistence;

use CodeIgniter\Database\RawSql;

/**
 * Persists normalized geographic region rows.
 *
 * Upserts each row into `geographic_regions` keyed by
 * `higher_geography_identifier`, resolving the owning `data_source_id` from
 * the row's `data_source_abbr` (defaulting to `IREC`) via the
 * `data_sources` table.
 */
class GeographicRegionsImportService implements EntityImportServiceInterface
{
    /**
     * Persist a batch of normalized geographic region rows.
     *
     * Rows missing `higher_geography_identifier`, `higher_geography`, or
     * `location_type` are skipped, as are rows whose `data_source_abbr` does
     * not resolve to a known `data_sources` row (data source lookups are
     * cached per-batch to avoid repeated queries). Matching rows are
     * identified solely by `higher_geography_identifier`; matches are
     * updated in place, everything else is inserted.
     *
     * @param array<int, array<string, mixed>> $rows   Normalized region rows. Expected
     *                                                  keys: `higher_geography_identifier`,
     *                                                  `higher_geography`, `location_type`,
     *                                                  `data_source_abbr`, `footprint_geometry`
     *                                                  (WKT string or null).
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
        $dataSourcesTable = $db->table('data_sources');
        $cachedDataSourceIds = [];
        $identifiers = [];

        foreach ($rows as $row) {
            $identifier = trim((string) ($row['higher_geography_identifier'] ?? ''));

            if ($identifier !== '') {
                $identifiers[$identifier] = true;
            }
        }

        $existingRows = [];
        if ($identifiers !== []) {
            foreach ($db->table('geographic_regions')->whereIn('higher_geography_identifier', array_keys($identifiers))->get()->getResultArray() as $existingRow) {
                $existingRows[(string) $existingRow['higher_geography_identifier']] = $existingRow;
            }
        }

        foreach ($rows as $row) {
            try {
                $higherGeographyIdentifier = (string) ($row['higher_geography_identifier'] ?? '');
                $higherGeography = trim((string) ($row['higher_geography'] ?? ''));
                $locationType = trim((string) ($row['location_type'] ?? ''));
                $dataSourceAbbr = strtoupper(trim((string) ($row['data_source_abbr'] ?? 'IREC')));
                $footprintGeometry = $this->nullableString($row['footprint_geometry'] ?? null);

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

                $existing = $existingRows[$higherGeographyIdentifier] ?? null;

                if ($existing === null) {
                    $counts['inserted']++;

                    if (! $dryRun) {
                        $db->table('geographic_regions')->insert($payload);
                        $payload['id'] = $db->insertID();
                    }

                    $existingRows[$higherGeographyIdentifier] = $payload;

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
     * Coerce a scalar value into a trimmed, optionally length-limited string.
     *
     * @param mixed $value     Raw value to coerce; non-scalar values become null.
     * @param ?int  $maxLength Maximum string length to keep, or null for no limit.
     *
     * @return string|null Trimmed string, or null when empty/non-scalar.
     */
    private function nullableString($value, ?int $maxLength = null): ?string
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
     * SQLite (used in tests) has no spatial functions, so the WKT string is
     * stored as-is; other drivers wrap it in `ST_GeomFromText()` so the
     * database stores a proper geometry value.
     *
     * @param object      $db       Active database connection.
     * @param string|null $geometry WKT polygon/multipolygon string, or null.
     *
     * @return mixed Raw WKT string, a {@see RawSql} expression, or null.
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