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

        foreach ($rows as $row) {
            try {
                $externalKey = trim((string) ($row['external_key'] ?? ''));
                $title = trim((string) ($row['title'] ?? ''));
                $indiciaTaxonGroupId = (int) ($row['indicia_taxon_group_id'] ?? 0);

                if ($externalKey === '' || $indiciaTaxonGroupId === 0 || $title === '') {
                    $counts['skipped']++;
                    $counts['processed']++;
                    continue;
                }

                $payload = [
                    'title' => substr($title, 0, 200),
                    'external_key' => substr($externalKey, 0, 100),
                    'indicia_taxon_group_id' => $indiciaTaxonGroupId,
                    'deleted_at' => null,
                ];

                $existing = $db->table('taxon_groups')->where('external_key', $externalKey)->get()->getRowArray();

                if ($existing === null) {
                    $counts['inserted']++;

                    if (! $dryRun) {
                        $db->table('taxon_groups')->insert($payload);
                    }

                    $counts['processed']++;
                    continue;
                }

                $counts['updated']++;

                if (! $dryRun) {
                    $db->table('taxon_groups')->where('id', $existing['id'])->update($payload);
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
