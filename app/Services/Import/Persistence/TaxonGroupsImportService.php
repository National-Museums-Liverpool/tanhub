<?php

namespace App\Services\Import\Persistence;

/**
 * Persists normalized taxon group rows.
 *
 * Upserts each row into `taxon_groups` keyed by `external_key`.
 */
class TaxonGroupsImportService implements EntityImportServiceInterface
{
    /**
     * Persist a batch of normalized taxon group rows.
     *
     * Rows missing `external_key`, `title`, or a positive
     * `indicia_taxon_group_id` are skipped. Matching rows are identified
     * solely by `external_key`; matches are updated in place, everything
     * else is inserted.
     *
     * @param array<int, array<string, mixed>> $rows   Normalized taxon group rows.
     *                                                  Expected keys: `external_key`,
     *                                                  `title`, `indicia_taxon_group_id`,
     *                                                  `implied`.
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
                $indiciaTaxonGroupId = (int) ($row['indicia_taxon_group_id'] ?? 0);
                $implied = $this->toFlag($row['implied'] ?? 0);

                if ($externalKey === '' || $indiciaTaxonGroupId === 0 || $title === '') {
                    $counts['skipped']++;
                    $counts['processed']++;
                    continue;
                }

                $payload = [
                    'title' => substr($title, 0, 200),
                    'external_key' => substr($externalKey, 0, 100),
                    'indicia_taxon_group_id' => $indiciaTaxonGroupId,
                    'implied' => $implied,
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

    /**
     * Normalize a loosely-typed truthy/falsy value into a `0`/`1` flag.
     *
     * Accepts booleans, numeric values (`>0` is true), and common string
     * representations (`1`, `true`, `t`, `yes`, `y`, case-insensitive).
     * Anything else is treated as false.
     *
     * @param mixed $value Raw value to normalize.
     *
     * @return int `1` when truthy, otherwise `0`.
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
}
