<?php

namespace App\Services\Import\Persistence;

/**
 * Persists normalized taxa rows and accepted taxon names.
 */
class TaxaImportService implements TaxonomyEntityImportServiceInterface
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

        if (! $dryRun) {
            $db->transStart();
        }

        try {
            $groupMap = $this->prepareLookup('taxon_groups', 'external_key', 'id');
            $schemeMap = $this->prepareLookup('recording_schemes', 'external_key', 'id');
            $taxonRankMap = $this->prepareLookup('taxon_ranks', 'rank', 'id');
            $taxonRanks = config('Import')->taxonRanks;

            foreach ($rows as $row) {
                $taxonIdentifier = trim((string) ($row['taxon_identifier'] ?? ''));
                $sciNameIdentifier = trim((string) ($row['scientific_name_identifier'] ?? ''));
                $scientificName = trim((string) ($row['scientific_name'] ?? ''));
                $vernacularName = trim((string) ($row['vernacular_name'] ?? ''));
                $groupExternalKey = trim((string) ($row['taxon_group_external_key'] ?? ''));
                $schemeExternalKey = trim((string) ($row['recording_scheme_external_key'] ?? ''));
                $taxonRank = trim((string) ($row['taxon_rank'] ?? ''));

                if ($taxonIdentifier === '' || $sciNameIdentifier === '' || $scientificName === '') {
                    log_message('info', 'Skipping taxa row due to missing required fields: ' . var_export($row, TRUE));
                    $counts['skipped']++;
                    continue;
                }

                $groupId = $groupMap[$groupExternalKey] ?? null;
                $schemeId = $schemeMap[$schemeExternalKey] ?? null;
                $taxonRankId = $taxonRankMap[$taxonRank] ?? null;

                if ($groupId === null) {
                    log_message('info', 'Skipping taxa row due to missing taxon group: ' . var_export($row, TRUE));
                    $counts['skipped']++;
                    continue;
                }

                $taxaPayload = [
                    'taxon_identifier' => substr($taxonIdentifier, 0, 100),
                    'scientific_name_identifier' => substr($sciNameIdentifier, 0, 100),
                    'scientific_name' => substr($scientificName, 0, 200),
                    'scientific_name_authorship' => $this->nullableString($row['scientific_name_authorship'] ?? null, 100),
                    'vernacular_name' => substr($vernacularName, 0, 200),
                    'taxon_group_id' => $groupId,
                    'recording_scheme_id' => $schemeId,
                    'taxon_rank_id' => $taxonRankId,
                    'conservation_status' => $this->nullableString($row['conservation_status'] ?? null, 10),
                    'rarity_group_name' => 'Unassigned',
                    'blocked' => 0,
                    'blocked_reason' => null,
                    'deleted_at' => null,
                ];
                // Format $higherTaxaInRow to an associative array keyed by
                // rank.
                $higherTaxaInRow = array_combine(array_column($row['higher_taxa'], 'taxon_rank'), $row['higher_taxa']);
                // Dynamically add the FKs for the taxon ranks we are
                // supporting.
                foreach ($taxonRanks as $parentTaxonRank) {
                    // Don't try to find the taxon we are about to insert, we
                    // will point this rank to self later.
                    if ($parentTaxonRank === $taxonRank) {
                        continue;
                    }
                    $rankColumn = strtolower($parentTaxonRank) . '_id';
                    if (!empty($higherTaxaInRow[$parentTaxonRank])) {
                        $taxaPayload[$rankColumn] = $this->lookupParentTaxon($higherTaxaInRow[$parentTaxonRank]->organism_key) ?? null;
                    }
                    else
                    {
                        $taxaPayload[$rankColumn] = null;
                    }
                }

                $existingTaxa = $db->table('taxa')->where('taxon_identifier', $taxonIdentifier)->get()->getRowArray();

                if ($existingTaxa === null) {
                    $counts['inserted']++;

                    if (! $dryRun) {
                        $db->table('taxa')->insert($taxaPayload);
                        $taxaId = $db->insertId();
                    }
                } else {
                    $counts['updated']++;
                    $taxaId = (int) $existingTaxa['id'];
                    if (! $dryRun) {
                        $db->table('taxa')->where('id', $existingTaxa['id'])->update($taxaPayload);
                    }
                }
                if (! $dryRun) {
                    // Need to set the current rank's FK as a self-reference,
                    // so this taxon is included in searches for itself.
                    $taxonRankFkField = strtolower($taxonRank) . '_id';
                    $db->table('taxa')->where('id', $taxaId)->update([$taxonRankFkField => $taxaId]);
                }
                if ($dryRun) {
                }
            }

            if (! $dryRun) {
                $db->transComplete();

                if (! $db->transStatus()) {
                    throw new \RuntimeException('Taxa import transaction failed.');
                }
            }
        } catch (\Throwable $exception) {
            log_message('error', $exception->getMessage());
            $counts['errors']++;

            if (! $dryRun && $db->transStatus()) {
                $db->transRollback();
            }
        }

        return $counts;
    }

    /**
     * Fetch the PK for an existing parent taxon row.
     *
     * Since taxa are inserted in rank order, we should be able to lookup the
     * parent taxon by its taxon_identifier, which is unique.
     *
     * @param string $key
     *   Taxon identifier (organism_key) of the parent taxon to lookup.
     *
     * @return int
     *   Looked up parent taxon's PK.
     */
    private function lookupParentTaxon(string $key): int
    {
        $rows = db_connect()->table('taxa')
            ->select(['id'])
            ->where('deleted_at', null)
            ->where('taxon_identifier', $key)
            ->get()
            ->getResultArray();

        if (count($rows) <> 1) {
            throw new \RuntimeException('Failed to find unique parent for taxon identifier ' . $key);
        }
        return (int) $rows[0]['id'];
    }

    /**
     * @return array<string, int>
     */
    private function prepareLookup(string $table, string $keyColumn = 'external_key', string $valueColumn = 'id'): array
    {
        $rows = db_connect()->table($table)
            ->select([$valueColumn, $keyColumn])
            ->where('deleted_at', null)
            ->where("$keyColumn IS NOT NULL", null, false)
            ->get()
            ->getResultArray();

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row[$keyColumn]] = (int) $row[$valueColumn];
        }

        return $map;
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
