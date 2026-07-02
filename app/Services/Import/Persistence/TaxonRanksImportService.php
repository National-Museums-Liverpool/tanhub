<?php

namespace App\Services\Import\Persistence;

/**
 * Persists normalized taxon rank rows.
 */
class TaxonRanksImportService implements TaxonomyEntityImportServiceInterface
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
            $rank = trim((string) ($row['rank'] ?? ''));

            if ($rank === '') {
                $counts['skipped']++;
                continue;
            }

            $code = trim((string) ($row['code'] ?? ''));
            $sortOrder = max(0, (int) ($row['sort_order'] ?? 0));

            $payload = [
                'rank' => substr($rank, 0, 50),
                'code' => substr($code !== '' ? $code : strtolower(preg_replace('/[^a-z0-9]+/i', '_', $rank) ?? ''), 0, 50),
                'sort_order' => $sortOrder,
            ];

            $existing = $db->table('taxon_ranks')->where('rank', $rank)->get()->getRowArray();

            if ($existing === null) {
                $counts['inserted']++;

                if (! $dryRun) {
                    $db->table('taxon_ranks')->insert($payload);
                }

                continue;
            }

            $counts['updated']++;

            if (! $dryRun) {
                $db->table('taxon_ranks')->where('id', $existing['id'])->update($payload);
            }
        }

        return $counts;
    }
}
