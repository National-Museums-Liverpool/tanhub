<?php

namespace App\Services\Import\Persistence;

/**
 * Persists normalized taxon rank rows.
 *
 * Upserts each row into `taxon_ranks` keyed by `rank`.
 */
class TaxonRanksImportService implements EntityImportServiceInterface
{
    /**
     * Persist a batch of normalized taxon rank rows.
     *
     * Rows missing `rank` are skipped. Matching rows are identified solely
     * by `rank`; matches are updated in place, everything else is inserted.
     * When `abbr` is not supplied, it is derived from `rank` by
     * lower-casing and replacing runs of non-alphanumeric characters with
     * underscores (e.g. `Sub Species` -> `sub_species`).
     *
     * @param array<int, array<string, mixed>> $rows   Normalized taxon rank rows.
     *                                                  Expected keys: `rank`, `abbr`,
     *                                                  `sort_order`.
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
        $ranks = [];

        foreach ($rows as $row) {
            $rank = trim((string) ($row['rank'] ?? ''));

            if ($rank !== '') {
                $ranks[$rank] = true;
            }
        }

        $existingRows = [];
        if ($ranks !== []) {
            foreach ($db->table('taxon_ranks')->whereIn('rank', array_keys($ranks))->get()->getResultArray() as $existingRow) {
                $existingRows[(string) $existingRow['rank']] = $existingRow;
            }
        }

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

                $existing = $existingRows[$rank] ?? null;

                if ($existing === null) {
                    $counts['inserted']++;

                    if (! $dryRun) {
                        $db->table('taxon_ranks')->insert($payload);
                        $payload['id'] = $db->insertID();
                    }

                    $existingRows[$rank] = $payload;

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
