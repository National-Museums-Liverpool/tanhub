<?php

namespace App\Services\Import\Persistence;

use App\Models\OccurrenceModel;
use App\Models\TaxonModel;
use App\Models\TaxonNameModel;

/**
 * Persists normalized occurrence records into local tables.
 */
class OccurrenceImportService
{
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

        $taxonIdentifiers = array_values(array_unique(array_filter(array_map(static function (array $record): string {
            return (string) ($record['taxon_identifier'] ?? '');
        }, $records))));

        $taxaRows = $TaxonModel->select(['id', 'taxon_identifier'])
            ->whereIn('taxon_identifier', $taxonIdentifiers)
            ->findAll();

        $taxaByIdentifier = [];

        foreach ($taxaRows as $taxaRow) {
            $taxaByIdentifier[(string) $taxaRow['taxon_identifier']] = (int) $taxaRow['id'];
        }

        $givenNameIdentifiers = array_values(array_unique(array_filter(array_map(static function (array $record): string {
            return (string) ($record['given_name_identifier'] ?? '');
        }, $records))));

        $taxonNameByGivenNameIdentifier = [];

        if ($givenNameIdentifiers !== []) {
            $taxonNameRows = $taxonNameModel->select(['id', 'given_name_identifier'])
                ->whereIn('given_name_identifier', $givenNameIdentifiers)
                ->findAll();

            foreach ($taxonNameRows as $taxonNameRow) {
                $taxonNameByGivenNameIdentifier[(string) $taxonNameRow['given_name_identifier']] = (int) $taxonNameRow['id'];
            }
        }

        foreach ($records as $record) {
            try {
                $sourceName = (string) ($record['source_name'] ?? '');

                // Business rule: skip iRecord records when importing from mixed feeds.
                if ($sourceName !== '' && stripos($sourceName, 'irecord') !== false) {
                    $counts['skipped']++;
                    $counts['processed']++;
                    $counts['last_checkpoint'] = $this->recordCheckpoint($record, $counts['last_checkpoint']);
                    continue;
                }

                $remoteId = trim((string) ($record['remote_id'] ?? ''));
                $taxonIdentifier = trim((string) ($record['taxon_identifier'] ?? ''));
                $givenNameIdentifier = trim((string) ($record['given_name_identifier'] ?? ''));

                if ($remoteId === '' || $taxonIdentifier === '' || $givenNameIdentifier === '') {
                    $counts['skipped']++;
                    $counts['processed']++;
                    $counts['last_checkpoint'] = $this->recordCheckpoint($record, $counts['last_checkpoint']);
                    continue;
                }

                $taxonId = $taxaByIdentifier[$taxonIdentifier] ?? null;
                $taxonNameId = $taxonNameByGivenNameIdentifier[$givenNameIdentifier] ?? null;

                if ($taxonId === null || $taxonNameId === null) {
                    $counts['skipped']++;
                    $counts['processed']++;
                    $counts['last_checkpoint'] = $this->recordCheckpoint($record, $counts['last_checkpoint']);
                    continue;
                }

                $gridRef = trim((string) ($record['grid_ref'] ?? ''));
                $gridRef2km = trim((string) ($record['grid_ref_2km'] ?? ''));

                if ($gridRef === '' || $gridRef2km === '') {
                    $counts['skipped']++;
                    $counts['processed']++;
                    $counts['last_checkpoint'] = $this->recordCheckpoint($record, $counts['last_checkpoint']);
                    continue;
                }

                $uniqueKey = strtoupper($sourceAbbr) . ':' . $remoteId;

                $row = [
                    'unique_key' => $uniqueKey,
                    'taxon_id' => $taxonId,
                    'taxon_name_id' => $taxonNameId,
                    'from_date' => $this->nullableDate($record['from_date'] ?? null),
                    'to_date' => $this->nullableDate($record['to_date'] ?? null),
                    'grid_ref' => substr($gridRef, 0, 20),
                    'grid_ref_2km' => substr(strtoupper($gridRef2km), 0, 5),
                    'locality' => $this->nullableString($record['locality'] ?? null, 255),
                    'recorded_by' => substr((string) ($record['recorded_by'] ?? 'Unknown'), 0, 255),
                    'identified_by' => $this->nullableString($record['identified_by'] ?? null, 255),
                    'identification_verification_status' => substr((string) ($record['identification_verification_status'] ?? 'UN'), 0, 2),
                    'sex' => $this->nullableString($record['sex'] ?? null, 20),
                    'life_stage' => $this->nullableString($record['life_stage'] ?? null, 20),
                    'organism_quantity' => $this->nullableString($record['organism_quantity'] ?? null, 20),
                    'data_source_id' => $dataSourceId,
                    'blocked' => ! empty($record['blocked']) ? 1 : 0,
                    'blocked_reason' => $this->nullableText($record['blocked_reason'] ?? null),
                ];

                $existing = $occurrenceModel->where('unique_key', $uniqueKey)->first();

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
    private function nullableText($value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
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
