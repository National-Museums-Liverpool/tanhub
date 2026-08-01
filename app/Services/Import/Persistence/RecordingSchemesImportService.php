<?php

namespace App\Services\Import\Persistence;

/**
 * Persists normalized recording scheme rows.
 *
 * Upserts each row into `recording_schemes` keyed by `external_key`.
 */
class RecordingSchemesImportService implements EntityImportServiceInterface
{
    /**
     * Persist a batch of normalized recording scheme rows.
     *
     * Rows missing `external_key` or `title` are skipped. Matching rows are
     * identified solely by `external_key`; matches are updated in place,
     * everything else is inserted.
     *
     * @param array<int, array<string, mixed>> $rows   Normalized recording scheme rows.
     *                                                  Expected keys: `external_key`,
     *                                                  `title`, `description`.
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

        foreach ($rows as $row) {
            try {
                $externalKey = trim((string) ($row['external_key'] ?? ''));
                $title = trim((string) ($row['title'] ?? ''));
                $description = trim((string) ($row['description'] ?? ''));

                if ($externalKey === '' || $title === '') {
                    $counts['skipped']++;
                    $counts['processed']++;
                    continue;
                }

                $payload = [
                    'external_key' => substr($externalKey, 0, 16),
                    'title' => substr($title, 0, 100),
                    'description' => substr($description, 0, 255),
                    'deleted_at' => null,
                ];

                $existing = $db->table('recording_schemes')->where('external_key', $externalKey)->get()->getRowArray();

                if ($existing === null) {
                    $counts['inserted']++;

                    if (! $dryRun) {
                        $db->table('recording_schemes')->insert($payload);
                    }

                    $counts['processed']++;
                    continue;
                }

                $counts['updated']++;

                if (! $dryRun) {
                    $db->table('recording_schemes')->where('id', $existing['id'])->update($payload);
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
