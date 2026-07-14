<?php

namespace App\Services\Import\Persistence;

/**
 * Persists normalized taxon name rows.
 */
class TaxonNamesImportService implements EntityImportServiceInterface
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

        try {
            $taxonIdByIdentifier = $this->prepareTaxonLookup();
        } catch (\Throwable $exception) {
            log_message('error', $exception->getMessage());
            $counts['errors']++;

            return $counts;
        }

        foreach ($rows as $row) {
            try {
                $taxonIdentifier = trim((string) ($row['taxon_identifier'] ?? ''));
                $givenNameIdentifier = trim((string) ($row['given_name_identifier'] ?? ''));
                $name = trim((string) ($row['name'] ?? ''));

                if ($taxonIdentifier === '' || $givenNameIdentifier === '' || $name === '') {
                    $counts['skipped']++;
                    $counts['processed']++;
                    continue;
                }

                $taxonId = $taxonIdByIdentifier[$taxonIdentifier] ?? null;

                if ($taxonId === null) {
                    $counts['skipped']++;
                    $counts['processed']++;
                    continue;
                }

                $payload = [
                    'uuid' => $this->stableUuid($taxonIdentifier . '|' . $givenNameIdentifier),
                    'taxon_id' => $taxonId,
                    'name' => substr($name, 0, 200),
                    'given_name_identifier' => substr($givenNameIdentifier, 0, 100),
                    'accepted' => $this->toFlag($row['accepted'] ?? 0),
                    'scientific' => $this->toFlag($row['scientific'] ?? 0),
                    'deleted_at' => null,
                ];

                $existing = $db->table('taxon_names')
                    ->where('taxon_id', $taxonId)
                    ->where('given_name_identifier', $givenNameIdentifier)
                    ->get()
                    ->getRowArray();

                if ($existing === null) {
                    $counts['inserted']++;

                    if (! $dryRun) {
                        $db->table('taxon_names')->insert($payload);
                    }
                } else {
                    $counts['updated']++;

                    if (! $dryRun) {
                        $db->table('taxon_names')
                            ->where('id', $existing['id'])
                            ->update($payload);
                    }
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
     * @return array<string, int>
     */
    private function prepareTaxonLookup(): array
    {
        $rows = db_connect()->table('taxa')
            ->select(['id', 'taxon_identifier'])
            ->where('deleted_at', null)
            ->where('taxon_identifier IS NOT NULL', null, false)
            ->get()
            ->getResultArray();

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row['taxon_identifier']] = (int) $row['id'];
        }

        return $map;
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
