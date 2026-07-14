<?php

namespace App\Services\Import\Adapter;

use CodeIgniter\HTTP\CURLRequest;
use RuntimeException;

/**
 * Fetches normalized occurrence records from an NBN Atlas-compatible endpoint.
 */
class NbnAtlasOccurrencesAdapter implements OccurrenceSourceAdapterInterface
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly CURLRequest $client,
        private readonly array $config,
        private readonly int $timeout,
    ) {
    }

    /**
     * Fetch one normalized page of records.
     */
    public function fetchPage(?string $checkpoint, int $limit): ImportPage
    {
        $endpoint = (string) ($this->config['endpoint'] ?? '');

        if ($endpoint === '') {
            throw new RuntimeException('NBN endpoint is not configured. Set import.nbn.endpoint.');
        }

        $query = (array) ($this->config['query'] ?? []);
        $query['limit'] = $limit;

        $checkpointParam = (string) ($this->config['checkpoint_param'] ?? 'since');

        if ($checkpoint !== null && $checkpoint !== '') {
            $query[$checkpointParam] = $checkpoint;
        }

        $response = $this->client->get($endpoint, [
            'query' => $query,
            'http_errors' => false,
            'timeout' => $this->timeout,
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('NBN request failed with status ' . $response->getStatusCode());
        }

        $payload = json_decode($response->getBody(), true);

        if (! is_array($payload)) {
            throw new RuntimeException('NBN response was not valid JSON object/array.');
        }

        $records = $this->extractRecords($payload);
        $normalized = [];
        $lastCheckpointValue = $checkpoint;
        $checkpointField = (string) ($this->config['checkpoint_field'] ?? 'lastModified');

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $normalizedRecord = $this->normalizeRecord($record);

            if (isset($record[$checkpointField]) && is_scalar($record[$checkpointField])) {
                $lastCheckpointValue = (string) $record[$checkpointField];
                $normalizedRecord['_checkpoint'] = $lastCheckpointValue;
            }

            $normalized[] = $normalizedRecord;
        }

        $hasMore = count($records) >= $limit;

        return new ImportPage($normalized, $lastCheckpointValue, $hasMore);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractRecords(array $payload): array
    {
        $recordsKey = (string) ($this->config['records_key'] ?? 'occurrences');

        if (isset($payload[$recordsKey]) && is_array($payload[$recordsKey])) {
            return array_values(array_filter($payload[$recordsKey], 'is_array'));
        }

        if (isset($payload['records']) && is_array($payload['records'])) {
            return array_values(array_filter($payload['records'], 'is_array'));
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return array_values(array_filter($payload['data'], 'is_array'));
        }

        if (array_is_list($payload)) {
            return array_values(array_filter($payload, 'is_array'));
        }

        return [];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function normalizeRecord(array $record): array
    {
        $gridRef = (string) ($record['grid_ref'] ?? $record['gridReference'] ?? '');
        $gridRef2km = (string) ($record['grid_ref_2km'] ?? $record['gridReference2Km'] ?? '');

        if ($gridRef2km === '' && $gridRef !== '') {
            $gridRef2km = strtoupper(substr(str_replace(' ', '', $gridRef), 0, 5));
        }

        return [
            'remote_id' => (string) ($record['id'] ?? $record['occurrence_id'] ?? ''),
            'source_name' => (string) ($record['dataResourceName'] ?? $record['source_name'] ?? 'NBN Atlas'),
            'taxon_identifier' => (string) ($record['taxon_identifier'] ?? $record['taxonID'] ?? ''),
            'given_name_identifier' => (string) ($record['given_name_identifier'] ?? $record['scientificNameID'] ?? ''),
            'from_date' => $record['from_date'] ?? $record['eventDate'] ?? null,
            'to_date' => $record['to_date'] ?? null,
            'grid_ref' => $gridRef,
            'grid_ref_2km' => $gridRef2km,
            'locality' => $record['locality'] ?? null,
            'recorded_by' => $record['recorded_by'] ?? $record['recordedBy'] ?? null,
            'identified_by' => $record['identified_by'] ?? $record['identifiedBy'] ?? null,
            'identification_verification_status' => $record['identification_verification_status'] ?? 'UN',
            'sex' => $record['sex'] ?? null,
            'life_stage' => $record['life_stage'] ?? null,
            'organism_quantity' => $record['organism_quantity'] ?? null,
            'blocked' => (bool) ($record['blocked'] ?? false),
            'blocked_reason' => $record['blocked_reason'] ?? null,
        ];
    }
}
