<?php

namespace App\Services\Import\Persistence;

/**
 * Persists normalized taxa rows and accepted taxon names.
 *
 * Upserts each row into `taxa` keyed by `taxon_identifier` (the UKSI
 * `organism_key`), resolving `taxon_group_id` (required), `recording_scheme_id`,
 * and `taxon_rank_id` foreign keys by external key/name lookup, and populating
 * one FK column per configured taxon rank (e.g. `family_id`, `genus_id`) from
 * the row's `higher_taxa` hierarchy via {@see self::lookupParentTaxon()}.
 */
class TaxaImportService implements EntityImportServiceInterface
{
    /**
     * Persist a batch of normalized taxa rows.
     *
     * Rows missing `taxon_identifier`, `scientific_name_identifier`, or
     * `scientific_name` are skipped, as are rows whose
     * `taxon_group_external_key` does not resolve to a known `taxon_groups`
     * row (taxon group is required; recording scheme and taxon rank are
     * optional and simply left null when unresolved). Lookup maps for
     * `taxon_groups`, `recording_schemes` (id and title), and `taxon_ranks`
     * are built once up front via {@see self::prepareLookup()} and
     * {@see self::prepareStringLookup()}; a failure while building these
     * lookups aborts the whole batch with a single error.
     *
     * For each row, `higher_taxa` (a list of ancestor taxon summaries keyed
     * by `taxon_rank`) is reindexed by rank, then for every configured
     * taxon rank other than the row's own rank, the corresponding `<rank>_id`
     * column is populated by looking up the ancestor's `organism_key` via
     * {@see self::lookupParentTaxon()} (or left null when no ancestor of
     * that rank is present). The row is upserted by `taxon_identifier`; on
     * insert, `rarity_group_name` defaults to the recording scheme's title
     * (see {@see self::defaultRarityGroupName()}). After insert/update, the
     * taxon's own rank FK column (e.g. `species_id` for a species row) is
     * set to self-reference its own primary key, so the taxon is included
     * when searching for descendants/self at its own rank. `id_difficulty`
     * is copied through verbatim when present in the row.
     *
     * @param array<int, array<string, mixed>> $rows   Normalized taxa rows. Expected keys:
     *                                                  `taxon_identifier`, `scientific_name_identifier`,
     *                                                  `scientific_name`, `scientific_name_authorship`,
     *                                                  `vernacular_name`, `taxon_group_external_key`,
     *                                                  `recording_scheme_external_key`, `taxon_rank`,
     *                                                  `id_difficulty`, `conservation_status`,
     *                                                  `higher_taxa` (list of ancestor objects with
     *                                                  `taxon_rank` and `organism_key`).
     * @param bool                             $dryRun When true, compute counts without
     *                                                  writing changes (foreign-key self-reference
     *                                                  updates are also skipped).
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
            $groupMap = $this->prepareLookup('taxon_groups', 'external_key', 'id');
            $schemeMap = $this->prepareLookup('recording_schemes', 'external_key', 'id');
            $schemeTitleMap = $this->prepareStringLookup('recording_schemes', 'external_key', 'title');
            $taxonRankMap = array_change_key_case($this->prepareLookup('taxon_ranks', 'rank', 'id'), CASE_LOWER);
            $taxonRanks = $this->configuredTaxonRanks();

            $taxonIdentifiers = [];
            $parentIdentifiers = [];

            foreach ($rows as $row) {
                $identifier = trim((string) ($row['taxon_identifier'] ?? ''));

                if ($identifier !== '') {
                    $taxonIdentifiers[$identifier] = true;
                }

                foreach ((array) ($row['higher_taxa'] ?? []) as $parent) {
                    $parentIdentifier = $this->taxonSummaryValue($parent, 'organism_key');

                    if ($parentIdentifier !== '') {
                        $parentIdentifiers[$parentIdentifier] = true;
                    }
                }

                $parentIdentifier = trim((string) ($row['parent_taxon_identifier'] ?? ''));
                if ($parentIdentifier !== '') {
                    $parentIdentifiers[$parentIdentifier] = true;
                }
            }

            $allIdentifiers = array_keys($taxonIdentifiers + $parentIdentifiers);
            $knownTaxa = $this->loadTaxaByIdentifier($db, $allIdentifiers);
        } catch (\Throwable $exception) {
            log_message('error', $exception->getMessage());
            $counts['errors']++;

            return $counts;
        }

        foreach ($rows as $row) {
            try {
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
                    $counts['processed']++;
                    continue;
                }

                $groupId = $groupMap[$groupExternalKey] ?? null;
                $schemeId = $schemeMap[$schemeExternalKey] ?? null;
                $taxonRankId = $taxonRankMap[strtolower($taxonRank)] ?? null;

                if ($groupId === null) {
                    log_message('info', 'Skipping taxa row due to missing taxon group: ' . var_export($row, TRUE));
                    $counts['skipped']++;
                    $counts['processed']++;
                    continue;
                }

                $taxaPayload = [
                    'taxon_identifier' => substr($taxonIdentifier, 0, 100),
                    'scientific_name_identifier' => substr($sciNameIdentifier, 0, 100),
                    'scientific_name' => substr($scientificName, 0, 200),
                    'scientific_name_authorship' => $this->nullableString($row['scientific_name_authorship'] ?? null, 100),
                    'vernacular_name' => substr($vernacularName, 0, 200),
                    'taxon_group_id' => $groupId,
                    'id_difficulty' => isset($row['id_difficulty']) ? (int) $row['id_difficulty'] : null,
                    'recording_scheme_id' => $schemeId,
                    'taxon_rank_id' => $taxonRankId,
                    'parent_taxon_id' => null,
                    'conservation_status' => $this->nullableString($row['conservation_status'] ?? null, 10),
                    'blocked' => 0,
                    'blocked_reason' => null,
                    'deleted_at' => null,
                ];
                $higherTaxaInRow = $this->higherTaxaByRank((array) ($row['higher_taxa'] ?? []));
                $parentIdentifier = trim((string) ($row['parent_taxon_identifier'] ?? ''));
                if ($parentIdentifier !== '') {
                    if (! isset($knownTaxa[$parentIdentifier])) {
                        throw new \RuntimeException('Failed to find unique parent for taxon identifier ' . $parentIdentifier);
                    }
                    $taxaPayload['parent_taxon_id'] = (int) $knownTaxa[$parentIdentifier]['id'];
                }
                // Dynamically add the FKs for the taxon ranks we are
                // supporting.
                foreach ($taxonRanks as $parentTaxonRank) {
                    // Don't try to find the taxon we are about to insert, we
                    // will point this rank to self later.
                    if (strcasecmp($parentTaxonRank, $taxonRank) === 0) {
                        continue;
                    }
                    $rankColumn = $this->rankColumn($parentTaxonRank);
                    $parentRank = strtolower($parentTaxonRank);
                    if (isset($higherTaxaInRow[$parentRank])) {
                        $parentIdentifier = $this->taxonSummaryValue($higherTaxaInRow[$parentRank], 'organism_key');

                        if (! isset($knownTaxa[$parentIdentifier])) {
                            throw new \RuntimeException('Failed to find unique parent for taxon identifier ' . $parentIdentifier);
                        }

                        $taxaPayload[$rankColumn] = (int) $knownTaxa[$parentIdentifier]['id'];
                    }
                    else {
                        $taxaPayload[$rankColumn] = null;
                    }
                }

                $existingTaxa = $knownTaxa[$taxonIdentifier] ?? null;

                if ($existingTaxa === null) {
                    $counts['inserted']++;
                    $insertPayload = $taxaPayload;
                    $insertPayload['rarity_group_name'] = $this->defaultRarityGroupName($schemeExternalKey, $schemeTitleMap);

                    if (! $dryRun) {
                        $db->table('taxa')->insert($insertPayload);
                        $taxaId = $db->insertId();
                        $knownTaxa[$taxonIdentifier] = $insertPayload + ['id' => $taxaId];
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
                    if (in_array(strtolower($taxonRank), array_map('strtolower', $taxonRanks), true)) {
                        $db->table('taxa')->where('id', $taxaId)->update([$this->rankColumn($taxonRank) => $taxaId]);
                    }

                    foreach ((array) (config('Import')->taxonRankMappings ?? []) as $sourceRank => $reportingRank) {
                        if (strcasecmp(trim((string) $sourceRank), $taxonRank) === 0
                            && ! isset($higherTaxaInRow[strtolower((string) $reportingRank)])) {
                            $db->table('taxa')->where('id', $taxaId)->update([
                                $this->rankColumn((string) $reportingRank) => $taxaId,
                            ]);
                        }
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
     * Load only the taxa referenced by one import page.
     *
     * @param object             $db Database connection.
     * @param array<int, string> $identifiers Taxon identifiers to fetch.
     *
     * @return array<string, array<string, mixed>> Taxa keyed by identifier.
     */
    private function loadTaxaByIdentifier(object $db, array $identifiers): array
    {
        if ($identifiers === []) {
            return [];
        }

        $rows = $db->table('taxa')
            ->whereIn('taxon_identifier', $identifiers)
            ->where('deleted_at', null)
            ->get()
            ->getResultArray();
        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row['taxon_identifier']] = $row;
        }

        return $result;
    }

    /**
     * Return configured reporting ranks as trimmed strings.
     *
     * @return array<int, string> Configured reporting rank names.
     */
    private function configuredTaxonRanks(): array
    {
        $configuredRanks = config('Import')->taxonRanks ?? [];
        $configuredRanks = is_array($configuredRanks) ? $configuredRanks : explode(',', (string) $configuredRanks);

        return array_values(array_filter(array_map(
            static fn ($rank): string => is_scalar($rank) ? trim((string) $rank) : '',
            $configuredRanks,
        ), static fn (string $rank): bool => $rank !== ''));
    }

    /**
     * Convert a reporting rank name into its FK column name.
     *
     * @param string $rank Rank name.
     * @return string Normalised rank FK column name.
     */
    private function rankColumn(string $rank): string
    {
        $alias = preg_replace('/[^a-z0-9]+/i', '_', strtolower(trim($rank)));

        return trim((string) $alias, '_') . '_id';
    }

    /**
     * Reindex higher-taxon summaries by case-insensitive rank name.
     *
     * @param array<int, mixed> $summaries Higher-taxon summaries.
     * @return array<string, mixed> Summaries keyed by lower-case rank.
     */
    private function higherTaxaByRank(array $summaries): array
    {
        $indexed = [];

        foreach ($summaries as $summary) {
            $rank = strtolower($this->taxonSummaryValue($summary, 'taxon_rank'));
            if ($rank !== '') {
                $indexed[$rank] = $summary;
            }
        }

        return $indexed;
    }

    /**
     * Read a field from an object or array taxon summary.
     *
     * @param mixed  $summary Taxon summary.
     * @param string $field   Field name.
     * @return string Trimmed field value.
     */
    private function taxonSummaryValue($summary, string $field): string
    {
        if (is_object($summary)) {
            return trim((string) ($summary->{$field} ?? ''));
        }

        if (is_array($summary)) {
            return trim((string) ($summary[$field] ?? ''));
        }

        return '';
    }

    /**
     * Build an in-memory lookup map from a table's key column to its value column.
     *
     * Used to resolve foreign keys (taxon group, recording scheme, taxon rank)
     * once per batch instead of once per row.
     *
     * @param string $table       Source table name.
     * @param string $keyColumn   Column to use as the lookup key (default `external_key`).
     * @param string $valueColumn Column to use as the lookup value (default `id`).
     *
     * @return array<string, int> Map of key column value to value column value,
     *                            restricted to non-deleted rows with a non-null key.
     */
    private function prepareLookup(string $table, string $keyColumn = 'external_key', string $valueColumn = 'id'): array
    {
        $db = db_connect();

        $rows = $db->table($table)
            ->select([$valueColumn, $keyColumn])
            ->where('deleted_at', null)
            ->where($db->escape($keyColumn) . ' IS NOT NULL', null, false)
            ->get()
            ->getResultArray();

        $map = [];

        foreach ($rows as $row) {
            $map[(string) $row[$keyColumn]] = (int) $row[$valueColumn];
        }

        return $map;
    }

    /**
     * Create an in-memory lookup of string values for a given table.
     *
     * Keyed by a given column.
     *
     * @return array<string, string>
     *   Lookup array.
     */
    private function prepareStringLookup(string $table, string $keyColumn, string $valueColumn): array
    {
        $rows = db_connect()->table($table)
            ->select([$valueColumn, $keyColumn])
            ->where('deleted_at', null)
            ->where("$keyColumn IS NOT NULL", null, false)
            ->where("$valueColumn IS NOT NULL", null, false)
            ->get()
            ->getResultArray();

        $map = [];

        foreach ($rows as $row) {
            $key = trim((string) $row[$keyColumn]);
            $value = trim((string) $row[$valueColumn]);

            if ($key === '' || $value === '') {
                continue;
            }

            $map[$key] = $value;
        }

        return $map;
    }

    /**
     * Resolve the default `rarity_group_name` for a newly inserted taxon.
     *
     * Falls back to `Unassigned` when the taxon's recording scheme is
     * missing or has no title, so every taxon always has a non-empty rarity
     * group for {@see \App\Services\Stats\TaxonRarityService} to group by.
     *
     * @param string                $schemeExternalKey Row's recording scheme external key.
     * @param array<string, string> $schemeTitleMap    Map of recording scheme external key to title.
     *
     * @return string Rarity group name, truncated to 100 characters.
     */
    private function defaultRarityGroupName(string $schemeExternalKey, array $schemeTitleMap): string
    {
        $schemeTitle = trim((string) ($schemeTitleMap[$schemeExternalKey] ?? ''));

        if ($schemeTitle === '') {
            return 'Unassigned';
        }

        return substr($schemeTitle, 0, 100);
    }

    /**
     * Coerce a scalar value into a trimmed, length-limited string.
     *
     * @param mixed $value     Raw value to coerce; non-scalar values become null.
     * @param int   $maxLength Maximum string length to keep.
     *
     * @return string|null Trimmed string, or null when empty/non-scalar.
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

    /**
     * Build a deterministic UUID-like value from a seed string.
     *
     * Note: retained for parity with the other import services' UUID helper
     * but not currently called from {@see self::import()}, since `taxa` rows
     * are keyed by `taxon_identifier` rather than a generated UUID.
     *
     * @param string $seed Identifier seed.
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
