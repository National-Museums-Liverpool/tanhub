<?php

namespace App\Services\Import\Persistence;

/**
 * Persists normalized taxon name rows.
 *
 * Upserts each row into `taxon_names` keyed by the composite of `taxon_id`
 * (resolved from `taxon_identifier` via {@see \App\Models\TaxonModel}-backed
 * lookup on the `taxa` table) and `given_name_identifier`.
 */
class TaxonNamesImportService implements EntityImportServiceInterface
{
    /**
     * Persist a batch of normalized taxon name rows.
     *
     * Rows missing `taxon_identifier`, `given_name_identifier`, or `name`
     * are skipped, as are rows whose `taxon_identifier` does not resolve to
     * a known, non-deleted `taxa` row (the taxon lookup is built once up
     * front via {@see self::prepareTaxonLookup()}). The upsert key is the
     * pair (`taxon_id`, `given_name_identifier`); a deterministic UUID is
     * derived from `taxon_identifier|given_name_identifier` via
     * {@see self::stableUuid()} so re-imports produce the same row identity.
     *
     * @param array<int, array<string, mixed>> $rows   Normalized taxon name rows.
     *                                                  Expected keys: `taxon_identifier`,
     *                                                  `given_name_identifier`, `name`,
     *                                                  `accepted`, `scientific`.
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

        try {
            $taxonIdentifiers = [];
            $givenNameIdentifiers = [];

            foreach ($rows as $row) {
                $taxonIdentifier = trim((string) ($row['taxon_identifier'] ?? ''));
                $givenNameIdentifier = trim((string) ($row['given_name_identifier'] ?? ''));

                if ($taxonIdentifier !== '') {
                    $taxonIdentifiers[$taxonIdentifier] = true;
                }

                if ($givenNameIdentifier !== '') {
                    $givenNameIdentifiers[$givenNameIdentifier] = true;
                }
            }

            $taxonIdByIdentifier = $this->prepareTaxonLookup($db, array_keys($taxonIdentifiers));
            $existingRows = [];

            if ($taxonIdByIdentifier !== [] && $givenNameIdentifiers !== []) {
                foreach ($db->table('taxon_names')
                    ->whereIn('taxon_id', array_values($taxonIdByIdentifier))
                    ->whereIn('given_name_identifier', array_keys($givenNameIdentifiers))
                    ->get()
                    ->getResultArray() as $existingRow) {
                    $existingRows[(int) $existingRow['taxon_id'] . '|' . (string) $existingRow['given_name_identifier']] = $existingRow;
                }
            }
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
                    log_message('info', 'Skipping taxa row due to missing taxon identifier, given name identifier, or name: ' . var_export($row, TRUE));
                    $counts['skipped']++;
                    $counts['processed']++;
                    continue;
                }

                $taxonId = $taxonIdByIdentifier[$taxonIdentifier] ?? null;

                if ($taxonId === null) {
                    log_message('info', 'Skipping taxa row due to missing taxon ID for taxon identifier: ' . $taxonIdentifier);
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

                $lookupKey = $taxonId . '|' . $givenNameIdentifier;
                $existing = $existingRows[$lookupKey] ?? null;

                if ($existing === null) {
                    $counts['inserted']++;

                    if (! $dryRun) {
                        $db->table('taxon_names')->insert($payload);
                        $payload['id'] = $db->insertID();
                    }

                    $existingRows[$lookupKey] = $payload;
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
     * Build an in-memory lookup of taxon primary keys by `taxon_identifier`.
     *
     * Loaded once per batch so each row can resolve its owning taxon without
     * a per-row query.
     *
     * @return array<string, int> Map of `taxon_identifier` to `taxa.id`.
     */
    private function prepareTaxonLookup(object $db, array $identifiers): array
    {
        if ($identifiers === []) {
            return [];
        }

        $rows = $db->table('taxa')
            ->select(['id', 'taxon_identifier'])
            ->whereIn('taxon_identifier', $identifiers)
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

    /**
     * Build a deterministic UUID-like value from a seed string.
     *
     * Used so repeated imports of the same `taxon_identifier|given_name_identifier`
     * combination generate the same `uuid`, keeping row identity stable
     * across re-imports.
     *
     * @param string $seed Identifier seed, e.g. `<taxon_identifier>|<given_name_identifier>`.
     *
     * @return string Deterministic UUID-v4-shaped string derived from the seed.
     */
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
