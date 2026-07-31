<?php

namespace App\Services\Import\Persistence;

use App\Models\OccurrenceModel;
use App\Models\TaxonModel;
use App\Models\TaxonNameModel;
use App\Services\Import\Support\OsgbGridReferenceBuilder;
use Throwable;

/**
 * Persists normalized occurrence records into local tables.
 */
class OccurrenceImportService
{
    /**
     * @param OsgbGridReferenceBuilder|null $osgbGridReferenceBuilder Grid reference conversion helper.
     */
    public function __construct(
        private readonly ?OsgbGridReferenceBuilder $osgbGridReferenceBuilder = null,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $records
     * @return array<string, int>
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
        $osgbGridReferenceBuilder = $this->osgbGridReferenceBuilder ?? new OsgbGridReferenceBuilder();


        $ranks = config('Import')->taxonRanks;
        $rankColumns = array_map(fn ($rank) => strtolower($rank) . '_id', $ranks);
        log_message('debug', 'Rank columns: ' . var_export($rankColumns, true));

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

                $linkedTaxon = $TaxonModel->where('scientific_name_identifier', $tvk)->first();
                $linkedTaxonName = $taxonNameModel->where('given_name_identifier', $givenNameTvk)->first();
                // If using a redundant given name, can map to the accepted name as a compromise.
                if ($linkedTaxon !== null && $linkedTaxonName === null) {
                    $linkedTaxonName = $taxonNameModel->where('given_name_identifier', $tvk)->first();
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

                $existing = $occurrenceModel->where('unique_key', $uniqueKey)->first();

                if ($this->shouldSkipIrecordOwnedNbnUpdate($sourceAbbr, $record, $existing)) {
                    log_message('debug', 'Skipping occurrence record due to being NBN copy of iRecord data: ' . var_export($record, true));
                    $counts['skipped']++;
                    $counts['processed']++;
                    $counts['last_checkpoint'] = $this->recordCheckpoint($record, $counts['last_checkpoint']);
                    continue;
                }

                if ($existing !== null) {
                    $counts['updated']++;

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
     * @param mixed $value
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

    /**
     * @param mixed $value
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
     * @param mixed $value
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
     * @param array<string, mixed> $record
     */
    private function resolveUniqueKey(array $record, string $sourceAbbr, string $remoteId): string
    {
        $normalisedSourceAbbr = strtoupper($sourceAbbr);

        if ($normalisedSourceAbbr === 'NBN' && $this->isIrecordOriginNbnRecord($record, $remoteId)) {
            return 'IREC:' . ($record['occurrence_id'] ?? $remoteId);
        }

        return $normalisedSourceAbbr . ':' . $remoteId;
    }

    /**
     * Skip updates to iRecord-owned records when processing NBN fallback data.
     *
     * @param array<string, mixed>      $record
     * @param array<string, mixed>|null $existing
     */
    private function shouldSkipIrecordOwnedNbnUpdate(string $sourceAbbr, array $record, ?array $existing): bool
    {
        if (strtoupper($sourceAbbr) !== 'NBN' || $existing === null) {
            return false;
        }

        $remoteId = trim((string) ($record['remote_id'] ?? ''));

        if (! $this->isIrecordOriginNbnRecord($record, $remoteId)) {
            return false;
        }

        $irecDataSourceId = $this->resolveDataSourceIdByAbbr('IREC');

        if ($irecDataSourceId === null) {
            return false;
        }

        return (int) ($existing['data_source_id'] ?? 0) === $irecDataSourceId;
    }

    /**
     * Determine whether the NBN record represents an iRecord-origin occurrence.
     *
     * @param array<string, mixed> $record
     */
    private function isIrecordOriginNbnRecord(array $record, string $remoteId): bool
    {
        if ($remoteId === '' || ! ctype_digit($remoteId)) {
            return false;
        }

        $provider = trim((string) ($record['data_provider_name'] ?? ''));

        if ($provider !== 'Biological Records Centre') {
            return false;
        }

        $sourceName = trim((string) ($record['source_name'] ?? ''));

        return $sourceName !== '' && stripos($sourceName, 'iRecord') !== false;
    }

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
     * @param array<string, mixed> $record
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
