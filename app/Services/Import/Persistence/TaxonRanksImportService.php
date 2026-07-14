<?php

namespace App\Services\Import\Persistence;

/**
 * Persists normalized taxon rank rows.
 */
class TaxonRanksImportService implements EntityImportServiceInterface
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
                $rank = trim((string) ($row['rank'] ?? ''));

                if ($rank === '') {
                    $counts['skipped']++;
                    $counts['processed']++;
                    continue;
                }

                $abbr = trim((string) ($row['abbr'] ?? ''));
                $sortOrder = max(0, (int) ($row['sort_order'] ?? 0));

                $payload = [
                    'rank' => substr($rank, 0, 50),
                    'abbr' => substr($abbr !== '' ? $abbr : strtolower(preg_replace('/[^a-z0-9]+/i', '_', $rank) ?? ''), 0, 50),
                    'sort_order' => $sortOrder,
                ];

                $existing = $db->table('taxon_ranks')->where('rank', $rank)->get()->getRowArray();

                if ($existing === null) {
                    $counts['inserted']++;

                    if (! $dryRun) {
                        $db->table('taxon_ranks')->insert($payload);
                    }

                    $counts['processed']++;
                    continue;
                }

                $counts['updated']++;

                if (! $dryRun) {
                    $db->table('taxon_ranks')->where('id', $existing['id'])->update($payload);
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
