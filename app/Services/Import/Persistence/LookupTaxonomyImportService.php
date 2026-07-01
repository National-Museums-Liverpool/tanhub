<?php

namespace App\Services\Import\Persistence;

/**
 * Persists taxonomy lookup rows to one of the rank lookup tables.
 */
class LookupTaxonomyImportService implements TaxonomyEntityImportServiceInterface
{
    public function __construct(
        private readonly string $table,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    public function import(array $rows, bool $dryRun = false): array
    {
        $counts = [
            'fetched' => count($rows),
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        if ($rows === []) {
            return $counts;
        }

        $db = db_connect();

        if (! $dryRun) {
            $db->transStart();
        }

        try {
            foreach ($rows as $row) {
                $taxonIdentifier = trim((string) ($row['taxon_identifier'] ?? ''));
                $scientificNameIdentifier = trim((string) ($row['scientific_name_identifier'] ?? ''));
                $scientificName = trim((string) ($row['scientific_name'] ?? ''));

                if ($taxonIdentifier === '' || $scientificNameIdentifier === '' || $scientificName === '') {
                    $counts['skipped']++;
                    continue;
                }

                $payload = [
                    'taxon_identifier' => substr($taxonIdentifier, 0, 100),
                    'scientific_name_identifier' => substr($scientificNameIdentifier, 0, 100),
                    'scientific_name' => substr($scientificName, 0, 200),
                    'scientific_name_authorship' => $this->nullableString($row['scientific_name_authorship'] ?? null, 100),
                    'vernacular_name' => substr((string) ($row['vernacular_name'] ?? $scientificName), 0, 200),
                    'deleted_at' => null,
                ];

                $existing = $db->table($this->table)->where('taxon_identifier', $taxonIdentifier)->get()->getRowArray();

                if ($existing === null) {
                    $counts['inserted']++;

                    if (! $dryRun) {
                        $db->table($this->table)->insert($payload);
                    }

                    continue;
                }

                $counts['updated']++;

                if (! $dryRun) {
                    $db->table($this->table)->where('id', $existing['id'])->update($payload);
                }
            }

            if (! $dryRun) {
                $db->transComplete();

                if (! $db->transStatus()) {
                    throw new \RuntimeException('Lookup taxonomy import transaction failed.');
                }
            }
        } catch (\Throwable $exception) {
            $counts['errors']++;

            if (! $dryRun && $db->transStatus()) {
                $db->transRollback();
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
}
