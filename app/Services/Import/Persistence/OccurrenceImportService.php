<?php

namespace App\Services\Import\Persistence;

use App\Models\OccurrenceModel;
use App\Models\TaxonModel;
use App\Models\TaxonNameModel;
use App\Services\Import\Support\OsgbGridReferenceBuilder;
use Throwable;

/**
 * Persists normalized occurrence records into local tables.
/**
 * Persists normalized occurrence records into local tables.
 *
 * Resolves each incoming record's taxon (via `scientific_name_identifier`)
 * and taxon name (via `given_name_identifier`, falling back to a redundant
 * given name mapped to the accepted name), regenerates the OSGB `grid_ref`
 * from coordinates when the source system is not already OSGB (see
 * {@see OsgbGridReferenceBuilder}), and upserts into `occurrences` keyed by
 * a source-specific `unique_key`. Implements the iRecord/NBN ownership rule
 * so NBN copies of iRecord-sourced occurrences are skipped once iRecord has
 * imported the canonical row (see {@see self::isIrecordOriginNbnRecord()}).
 */
class OccurrenceImportService
{
    /**
     * @param OsgbGridReferenceBuilder|null $osgbGridReferenceBuilder Grid reference conversion
     *                                                                helper; a default instance is
     *                                                                created when null.
     */
    public function __construct(
        private readonly ?OsgbGridReferenceBuilder $osgbGridReferenceBuilder = null,
    ) {
    }

    /**
     * Persist a batch of normalized occurrence records.
     *
     * A record is skipped (counted in both `skipped` and `processed`) when:
     * - `remote_id`, `scientific_name_identifier`, or `given_name_identifier` is missing.
     * - the taxon (`scientific_name_identifier`) or taxon name
     *   (`given_name_identifier`, or the redundant-name fallback) cannot be
     *   resolved against `taxa`/`taxon_names`.
     * - the source's `grid_ref_system` is non-OSGB and regenerating
     *   `grid_ref` from `latitude`/`longitude`/`coordinate_uncertainty_in_meters`
     *   fails (see {@see OsgbGridReferenceBuilder::buildFromWgs84()}), or the
     *   resulting/supplied `grid_ref` is still empty.
     * - the record is an NBN copy of an occurrence already owned by iRecord
     *   (see {@see self::shouldSkipIrecordOwnedNbnUpdate()}).
     *
     * The upsert key is `unique_key`, resolved via
     * {@see self::resolveUniqueKey()} (`<SOURCE>:<remote_id>`, or
     * `IREC:<occurrence_id>` for NBN records that originated from iRecord).
     * Matching rows are updated in place, everything else is inserted. Every
     * rank column configured in `Config\Import::$taxonRanks` (e.g.
     * `family_id`, `genus_id`) is copied from the resolved taxon onto the
     * occurrence row. `last_checkpoint` tracks the highest `_checkpoint`
     * value seen across all processed records (including skips), so the
     * caller can persist incremental import progress even when some records
     * are skipped or the batch stops early on error.
     *
     * @param array<int, array<string, mixed>> $records      Normalized occurrence records.
     *                                                        Expected keys: `remote_id`,
     *                                                        `scientific_name_identifier`,
     *                                                        `given_name_identifier`, `grid_ref`,
     *                                                        `grid_ref_system`, `grid_ref_2km`,
     *                                                        `latitude`, `longitude`,
     *                                                        `coordinate_uncertainty_in_meters`,
     *                                                        `from_date`, `to_date`, `locality`,
     *                                                        `recorded_by`, `identified_by`,
     *                                                        `identification_verification_status`,
     *                                                        `sex`, `life_stage`, `organism_quantity`,
     *                                                        `occurrence_id`, `data_provider_name`,
     *                                                        `source_name`, `_checkpoint`.
     * @param int                               $dataSourceId Foreign key of the owning `data_sources` row.
     * @param string                            $sourceAbbr   Data source abbreviation (e.g. `NBN`,
     *                                                        `IREC`, `INDI`) used to build `unique_key`
     *                                                        and to detect the iRecord ownership rule.
     * @param bool                              $dryRun       When true, compute counts without
     *                                                        writing changes.
     *
        * @return array<string, int|string|null|array<int, int>> Result counts: `fetched`, `processed`,
        *        `inserted`, `updated`, `skipped`, `errors`, `last_checkpoint` (highest `_checkpoint`
        *        value seen, or null), and `changed_occurrence_ids` (IDs inserted or updated).
     */
    public function import(array $records, int $dataSourceId, string $sourceAbbr, bool $dryRun = false): array
    {
        $counts = [
            'fetched' => count($records),
            'processed' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
            'last_checkpoint' => null,
            'changed_occurrence_ids' => [],
        ];

        if ($records === []) {
            return $counts;
        }

        /** @var TaxonModel $TaxonModel */
        $TaxonModel = model(TaxonModel::class);
        /** @var TaxonNameModel $taxonNameModel */
        $taxonNameModel = model(TaxonNameModel::class);
        /** @var OccurrenceModel $occurrenceModel */
        $occurrenceModel = model(OccurrenceModel::class);
        $db = db_connect();
        $osgbGridReferenceBuilder = $this->osgbGridReferenceBuilder ?? new OsgbGridReferenceBuilder();


        $ranks = config('Import')->taxonRanks;
        $rankColumns = array_map(fn ($rank) => strtolower($rank) . '_id', $ranks);
        $taxonIdentifiers = [];
        $givenNameIdentifiers = [];
        $uniqueKeys = [];

        foreach ($records as $record) {
            $taxonIdentifier = trim((string) ($record['scientific_name_identifier'] ?? ''));
            $givenNameIdentifier = trim((string) ($record['given_name_identifier'] ?? ''));
            $remoteId = trim((string) ($record['remote_id'] ?? ''));

            if ($taxonIdentifier !== '') {
                $taxonIdentifiers[$taxonIdentifier] = true;
            }

            if ($givenNameIdentifier !== '') {
                $givenNameIdentifiers[$givenNameIdentifier] = true;
            }

            if ($remoteId !== '') {
                $uniqueKeys[$this->resolveUniqueKey($record, $sourceAbbr, $remoteId)] = true;
            }
        }

        $taxaByIdentifier = $this->loadRowsByColumn($db, 'taxa', 'scientific_name_identifier', array_keys($taxonIdentifiers));
        $taxonNamesByIdentifier = $this->loadRowsByColumn($db, 'taxon_names', 'given_name_identifier', array_keys($givenNameIdentifiers));
        $fallbackIdentifiers = array_diff(array_keys($taxonIdentifiers), array_keys($taxonNamesByIdentifier));

        if ($fallbackIdentifiers !== []) {
            $taxonNamesByIdentifier += $this->loadRowsByColumn($db, 'taxon_names', 'given_name_identifier', $fallbackIdentifiers);
        }

        $occurrencesByUniqueKey = $this->loadRowsByColumn($db, 'occurrences', 'unique_key', array_keys($uniqueKeys));
        $irecDataSourceId = strtoupper($sourceAbbr) === 'NBN'
            ? $this->resolveDataSourceIdByAbbr('IREC')
            : null;

        foreach ($records as $record) {
            try {
                $remoteId = trim((string) ($record['remote_id'] ?? ''));
                $tvk = trim((string) ($record['scientific_name_identifier'] ?? ''));
                $givenNameTvk = trim((string) ($record['given_name_identifier'] ?? ''));

                if ($remoteId === '' || $tvk === '' || $givenNameTvk === '') {
                    log_message('debug', 'Skipping occurrence record due to missing identifiers: ' . var_export($record, true));
                    $counts['skipped']++;
                    $counts['processed']++;
                    $counts['last_checkpoint'] = $this->recordCheckpoint($record, $counts['last_checkpoint']);
                    continue;
                }

                $linkedTaxon = $taxaByIdentifier[$tvk] ?? null;
                $linkedTaxonName = $taxonNamesByIdentifier[$givenNameTvk] ?? null;
                // If using a redundant given name, can map to the accepted name as a compromise.
                if ($linkedTaxon !== null && $linkedTaxonName === null) {
                    $linkedTaxonName = $taxonNamesByIdentifier[$tvk] ?? null;
                }

                if ($linkedTaxon === null || $linkedTaxonName === null) {
                    log_message('debug', 'Skipping occurrence record due to missing linked taxon: ' . var_export($record, true));
                    $counts['skipped']++;
                    $counts['processed']++;
                    $counts['last_checkpoint'] = $this->recordCheckpoint($record, $counts['last_checkpoint']);
                    continue;
                }

                $gridRef = trim((string) ($record['grid_ref'] ?? ''));
                $gridRefSystem = trim((string) ($record['grid_ref_system'] ?? ''));
                $gridRef2km = trim((string) ($record['grid_ref_2km'] ?? ''));

                if ($this->shouldRegenerateGridReference($gridRefSystem)) {
                    $generatedGridRef = $osgbGridReferenceBuilder->buildFromWgs84(
                        $record['latitude'] ?? null,
                        $record['longitude'] ?? null,
                        $record['coordinate_uncertainty_in_meters'] ?? null,
                    );

                    if ($generatedGridRef === null) {
                        log_message('debug', 'Skipping occurrence record due to failed non-OSGB grid reference generation: ' . var_export($record, true));
                        $counts['skipped']++;
                        $counts['processed']++;
                        $counts['last_checkpoint'] = $this->recordCheckpoint($record, $counts['last_checkpoint']);
                        continue;
                    }

                    $gridRef = $generatedGridRef['grid_ref'];
                    $gridRef2km = $osgbGridReferenceBuilder->calculateDintyTetrad($gridRef) ?? '';
                }

                if ($gridRef === '') {
                    log_message('debug', 'Skipping occurrence record due to missing grid reference: ' . var_export($record, true));
                    $counts['skipped']++;
                    $counts['processed']++;
                    $counts['last_checkpoint'] = $this->recordCheckpoint($record, $counts['last_checkpoint']);
                    continue;
                }

                $uniqueKey = $this->resolveUniqueKey($record, $sourceAbbr, $remoteId);

                $row = [
                    'unique_key' => $uniqueKey,
                    'taxon_id' => $linkedTaxon['id'],
                    'taxon_name_id' => $linkedTaxonName['id'],
                    'from_date' => $this->nullableDate($record['from_date'] ?? null),
                    'to_date' => $this->nullableDate($record['to_date'] ?? null),
                    'grid_ref' => substr($gridRef, 0, 20),
                    'grid_ref_2km' => substr(strtoupper($gridRef2km), 0, 5),
                    'locality' => $this->nullableString($record['locality'] ?? null, 255),
                    'recorded_by' => $this->nullableString($record['recorded_by'] ?? null, 255) ?? 'Unknown',
                    'identified_by' => $this->nullableString($record['identified_by'] ?? null, 255),
                    'identification_verification_status' => substr((string) ($record['identification_verification_status'] ?? 'UN'), 0, 2),
                    'sex' => $this->nullableString($record['sex'] ?? null, 20),
                    'life_stage' => $this->nullableString($record['life_stage'] ?? null, 20),
                    'organism_quantity' => $this->nullableString($record['organism_quantity'] ?? null, 20),
                    'latitude' => $this->nullableFloat($record['latitude'] ?? null),
                    'longitude' => $this->nullableFloat($record['longitude'] ?? null),
                    'data_source_id' => $dataSourceId,
                ];
                foreach ($rankColumns as $rankColumn) {
                    $row[$rankColumn] = $linkedTaxon[$rankColumn] ?? null;
                }

                $existing = $occurrencesByUniqueKey[$uniqueKey] ?? null;

                if ($this->shouldSkipIrecordOwnedNbnUpdate($sourceAbbr, $record, $existing, $irecDataSourceId)) {
                    log_message('debug', 'Skipping occurrence record due to being NBN copy of iRecord data: ' . var_export($record, true));
                    $counts['skipped']++;
                    $counts['processed']++;
                    $counts['last_checkpoint'] = $this->recordCheckpoint($record, $counts['last_checkpoint']);
                    continue;
                }

                if ($existing !== null) {
                    $counts['updated']++;
                    $counts['changed_occurrence_ids'][] = (int) $existing['id'];

                    if (! $dryRun) {
                        $occurrenceModel->update((int) $existing['id'], $row);
                    }

                    $counts['processed']++;
                    $counts['last_checkpoint'] = $this->recordCheckpoint($record, $counts['last_checkpoint']);

                    continue;
                }

                $counts['inserted']++;

                if (! $dryRun) {
                    $occurrenceModel->insert($row);
                    $newId = (int) $occurrenceModel->getInsertID();
                    $counts['changed_occurrence_ids'][] = $newId;
                    $occurrencesByUniqueKey[$uniqueKey] = $row + ['id' => $newId];
                }

                $counts['processed']++;
                $counts['last_checkpoint'] = $this->recordCheckpoint($record, $counts['last_checkpoint']);
            } catch (\Throwable $exception) {
                log_message('error', $exception->getMessage());
                $counts['errors']++;
                break;
            }
        }

        return $counts;
    }

    /**
     * Coerce a scalar value into a `YYYY-MM-DD` date string.
     *
     * @param mixed $value Raw value; non-scalar or too-short values become null.
     *
     * @return string|null First 10 characters of the trimmed value, or null when invalid.
     */
    private function nullableDate($value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '') {
            return null;
        }

        if (strlen($string) >= 10) {
            return substr($string, 0, 10);
        }

        return null;
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
     * Coerce a scalar value into a nullable float.
     *
     * @param mixed $value Raw value to coerce; non-numeric/non-scalar values become null.
     *
     * @return float|null Float value, or null when empty/non-numeric.
     */
    private function nullableFloat($value): ?float
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        if ($string === '' || ! is_numeric($string)) {
            return null;
        }

        return (float) $string;
    }

    /**
     * Coerce a scalar value into a trimmed, unlimited-length string.
     *
     * @param mixed $value Raw value to coerce; non-scalar values become null.
     *
     * @return string|null Trimmed string, or null when empty/non-scalar.
     */
    private function nullableText($value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * Resolve the canonical occurrence unique key.
     *
     * NBN records that represent an iRecord-origin occurrence (see
     * {@see self::isIrecordOriginNbnRecord()}) use the iRecord key format so
     * they line up with the row iRecord itself will later import.
     *
     * @param array<string, mixed> $record     Normalized source record.
     * @param string                $sourceAbbr Data source abbreviation (e.g. `NBN`, `IREC`).
     * @param string                $remoteId   Source-native record identifier.
     *
     * @return string Unique key in the form `<SOURCE>:<id>`.
     */
    private function resolveUniqueKey(array $record, string $sourceAbbr, string $remoteId): string
    {
        $normalisedSourceAbbr = strtoupper($sourceAbbr);

        if ($normalisedSourceAbbr === 'NBN' && $this->isIrecordOriginNbnRecord($record)) {
            return 'IREC:' . trim((string) $record['occurrence_id']);
        }

        return $normalisedSourceAbbr . ':' . $remoteId;
    }

    /**
     * Skip updates to iRecord-owned records when processing NBN fallback data.
     *
     * Once the iRecord importer has claimed a shared `IREC:<occurrenceID>`
     * row (i.e. `existing.data_source_id` points at the `IREC` data source),
     * later NBN copies of that same iRecord-origin occurrence must not
     * overwrite it, since the NBN copy of the data is expected to lag behind
     * iRecord's own copy.
     *
     * @param string                     $sourceAbbr Data source abbreviation of the incoming record.
     * @param array<string, mixed>      $record     Normalized source record.
     * @param array<string, mixed>|null $existing   Existing `occurrences` row matched by
     *                                               `unique_key`, or null when none exists.
     *
     * @return bool True when this NBN record must be skipped rather than applied.
     */
    private function shouldSkipIrecordOwnedNbnUpdate(string $sourceAbbr, array $record, ?array $existing, ?int $irecDataSourceId = null): bool
    {
        if (strtoupper($sourceAbbr) !== 'NBN' || $existing === null) {
            return false;
        }

        if (! $this->isIrecordOriginNbnRecord($record)) {
            return false;
        }

        $irecDataSourceId ??= $this->resolveDataSourceIdByAbbr('IREC');

        if ($irecDataSourceId === null) {
            return false;
        }

        return (int) ($existing['data_source_id'] ?? 0) === $irecDataSourceId;
    }

    /**
     * Load rows keyed by a unique identifier column for one import page.
     *
     * @param object              $db Database connection.
     * @param string              $table Table to query.
     * @param string              $column Identifier column.
     * @param array<int, string>  $values Values to fetch.
     *
     * @return array<string, array<string, mixed>> Rows keyed by identifier.
     */
    private function loadRowsByColumn(object $db, string $table, string $column, array $values): array
    {
        if ($values === []) {
            return [];
        }

        $rows = $db->table($table)
            ->whereIn($column, $values)
            ->get()
            ->getResultArray();
        $result = [];

        foreach ($rows as $row) {
            $value = trim((string) ($row[$column] ?? ''));

            if ($value !== '') {
                $result[$value] = $row;
            }
        }

        return $result;
    }

    /**
     * Determine whether the NBN record represents an iRecord-origin occurrence.
     *
     * True only when `occurrence_id` is a purely numeric iRecord ID, the
     * `data_provider_name` is exactly `Biological Records Centre`, and
     * `source_name` mentions `iRecord` (case-insensitive) — matching the
     * special ownership rule documented in `docs/import.md`.
     *
     * @param array<string, mixed> $record Normalized source record.
     *
     * @return bool True when the record should be treated as iRecord-origin.
     */
    private function isIrecordOriginNbnRecord(array $record): bool
    {
        $occurrenceId = trim((string) ($record['occurrence_id'] ?? ''));

        if ($occurrenceId === '' || ! ctype_digit($occurrenceId)) {
            return false;
        }

        $provider = trim((string) ($record['data_provider_name'] ?? ''));

        if ($provider !== 'Biological Records Centre') {
            return false;
        }

        $sourceName = trim((string) ($record['source_name'] ?? ''));

        return $sourceName !== '' && stripos($sourceName, 'iRecord') !== false;
    }

    /**
     * Resolve a `data_sources.id` value by its `abbr` column.
     *
     * @param string $abbr Data source abbreviation to look up (case-insensitive).
     *
     * @return int|null Matching data source id, or null when not found or on query failure.
     */
    private function resolveDataSourceIdByAbbr(string $abbr): ?int
    {
        try {
            $row = db_connect()
                ->table('data_sources')
                ->select('id')
                ->where('abbr', strtoupper($abbr))
                ->get()
                ->getRowArray();
        } catch (Throwable) {
            return null;
        }

        if (! is_array($row) || ! isset($row['id'])) {
            return null;
        }

        return (int) $row['id'];
    }

    /**
     * Determine if this record should regenerate grid reference from coordinates.
     *
     * @param string $gridRefSystem Source grid reference system token.
     *
     * @return bool True when the source system is explicitly non-OSGB.
     */
    private function shouldRegenerateGridReference(string $gridRefSystem): bool
    {
        $normalised = strtoupper(trim($gridRefSystem));

        if ($normalised === '') {
            return false;
        }

        return ! in_array($normalised, ['OSGB', 'OSGB1936', 'EPSG:27700'], true);
    }

    /**
     * Advance the running checkpoint using a record's `_checkpoint` value.
     *
     * Called for every processed record (including skips) so the caller can
     * always persist the furthest point reached, even when the batch stops
     * early due to a per-record skip or an unhandled exception.
     *
     * @param array<string, mixed> $record   Normalized source record.
     * @param string|null           $fallback Current running checkpoint value.
     *
     * @return string|null New checkpoint value, or `$fallback` when the record has none.
     */
    private function recordCheckpoint(array $record, ?string $fallback): ?string
    {
        $checkpoint = $record['_checkpoint'] ?? null;

        if (! is_scalar($checkpoint)) {
            return $fallback;
        }

        $checkpoint = trim((string) $checkpoint);

        return $checkpoint !== '' ? $checkpoint : $fallback;
    }
}
