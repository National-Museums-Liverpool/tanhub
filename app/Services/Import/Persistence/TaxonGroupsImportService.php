<?php

namespace App\Services\Import\Persistence;

/**
 * Persists normalized taxon group rows.
 */
class TaxonGroupsImportService implements TaxonomyEntityImportServiceInterface
{
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

        foreach ($rows as $row) {
            $externalKey = trim((string) ($row['external_key'] ?? ''));
            $title = trim((string) ($row['title'] ?? ''));

            if ($externalKey === '' || $title === '') {
                $counts['skipped']++;
                continue;
            }

            $payload = [
                'title' => substr($title, 0, 200),
                'external_key' => substr($externalKey, 0, 100),
                'deleted_at' => null,
            ];

            $existing = $db->table('taxon_groups')->where('external_key', $externalKey)->get()->getRowArray();

            if ($existing === null) {
                $counts['inserted']++;

                if (! $dryRun) {
                    $db->table('taxon_groups')->insert($payload);
                }

                continue;
            }

            $counts['updated']++;

            if (! $dryRun) {
                $db->table('taxon_groups')->where('id', $existing['id'])->update($payload);
            }
        }

        return $counts;
    }
}
