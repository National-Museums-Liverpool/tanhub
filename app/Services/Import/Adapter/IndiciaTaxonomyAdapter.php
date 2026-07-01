<?php

namespace App\Services\Import\Adapter;

use CodeIgniter\HTTP\CURLRequest;
use InvalidArgumentException;
use RuntimeException;

/**
 * Fetches normalized taxonomy rows from Indicia report endpoints.
 */
class IndiciaTaxonomyAdapter implements TaxonomySourceAdapterInterface
{
    /**
     * @var array<int, string>
     */
    private const SUPPORTED_ENTITIES = [
        'taxa',
        'orders',
        'families',
        'superfamilies',
        'recording_schemes',
        'taxon_groups',
    ];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly CURLRequest $client,
        private readonly \Config\Import $config,
        private readonly array $dataSourceConfig,
    ) {
    }

    /**
     * Fetch one normalized taxonomy batch.
     */
    public function fetchBatch(string $entity, int $limit, int $offset): TaxonomyImportBatch
    {
        $entityKey = strtolower($entity);

        if (! in_array($entityKey, self::SUPPORTED_ENTITIES, true)) {
            throw new InvalidArgumentException('Unsupported taxonomy entity: ' . $entity);
        }

        $batchLimit = max(1, $limit);
        $batchOffset = max(0, $offset);

        // @todo: Fetch correct report depending on $entity.
        $rawRows = $this->fetchTaxonomyData($entityKey, $batchLimit, $batchOffset);
        $normalizedRows = $this->normalizeRows($entityKey, $rawRows);

        return new TaxonomyImportBatch(
            entity: $entityKey,
            offset: $batchOffset,
            rows: $normalizedRows,
            nextOffset: $batchOffset + count($rawRows),
            hasMore: count($rawRows) >= $batchLimit,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchTaxonomyData(string $entity, int $limit, int $offset): array
    {
        $url = rtrim((string) ($this->config->indiciaWarehouseUrl ?? ''), '/');

        if ($url === '') {
            throw new RuntimeException('Indicia taxonomy source URL is not configured.');
        }

        // Fetch correct report depending on $entity. Higher taxa share the
        // same report.
        switch ($entity) {
            case 'orders':
            case 'families':
            case 'superfamilies':
                $report = 'higher_taxa';
                break;

            case 'recording_schemes':
            case 'taxon_groups':
            case 'taxa':
                $report = $entity;
                break;

            default:
                throw new InvalidArgumentException('Unsupported taxonomy entity: ' . $entity);
        }

        $endpoint = "$url/index.php/services/rest/reports/projects/tanhub/$report.xml";
        $query = [
            // Fetch indicia.import.proj_id from config, default to empty string if not set.
            'proj_id' => (string) ($this->config->indiciaProjId ?? ''),
            'taxon_list_id' => (int) ($this->config->indiciaTaxonListId ?? 0),
            'limit' => $limit,
            'offset' => $offset,
        ];
        if ($report === 'higher_taxa') {
            $query['taxon_rank'] = $this->entityRank($entity);
        }
        $user = (string) ($this->config->indiciaUsername ?? '');
        $secret = (string) ($this->config->indiciaSecret ?? '');
        $response = $this->client->get($endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => "USER:$user:SECRET:$secret",
            ],
            'query' => $query,
            'http_errors' => false,
            'timeout' => $this->config->httpTimeout ?? 30,
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('Indicia taxonomy request failed with status ' . $response->getStatusCode() . ' for entity ' . $entity);
        }

        $payload = json_decode($response->getBody(), true);

        if (! is_array($payload)) {
            throw new RuntimeException('Indicia taxonomy response was not valid JSON for entity ' . $entity);
        }

        return $this->extractRecords($payload);
    }


    /**
     * @return array<int, array<string, mixed>>
     *
    private function fetchEndpointRows(string $endpointKey, ?string $checkpoint, int $limit): array
    {
        $endpoint = (string) ($this->config[$endpointKey] ?? '');

        log_message('info', 'Indicia taxonomy endpoint for {key}: {endpoint}', [
            'key' => $endpointKey,
            'endpoint' => $endpoint,
        ]);

        if ($endpoint === '') {
            return [];
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
            throw new RuntimeException('Indicia taxonomy request failed with status ' . $response->getStatusCode() . ' for ' . $endpointKey);
        }

        $payload = json_decode($response->getBody(), true);

        if (! is_array($payload)) {
            throw new RuntimeException('Indicia taxonomy response was not valid JSON for ' . $endpointKey);
        }

        return $this->extractRecords($payload);
    }
        */

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(string $entity, array $rows): array
    {
        return match ($entity) {
            'taxa' => array_map(fn (array $row): array => $this->normalizeTaxaRow($row), $rows),
            'orders' => $this->uniqueRowsByKey(array_map(fn (array $row): array => $this->normalizeLookupRow($row, 'order'), $rows), 'taxon_identifier'),
            'superfamilies' => $this->uniqueRowsByKey(array_map(fn (array $row): array => $this->normalizeLookupRow($row, 'superfamily'), $rows), 'taxon_identifier'),
            'families' => $this->uniqueRowsByKey(array_map(fn (array $row): array => $this->normalizeLookupRow($row, 'family'), $rows), 'taxon_identifier'),
            'recording_schemes' => $this->uniqueRowsByKey(array_map(fn (array $row): array => $this->normalizeRecordingSchemeRow($row), $rows), 'external_key'),
            'taxon_groups' => $this->uniqueRowsByKey(array_map(fn (array $row): array => $this->normalizeTaxonGroupRow($row), $rows), 'external_key'),
            default => [],
        };
    }

    private function entityRank(string $entity): ?string
    {
        return match ($entity) {
            'orders' => 'Order',
            'superfamilies' => 'Superfamily',
            'families' => 'Family',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractRecords(array $payload): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            return array_values(array_filter($payload['data'], 'is_array'));
        }
        return [];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeLookupRow(array $row, string $prefix): array
    {
        $taxonIdentifier = trim((string) ($row[$prefix . '_taxon_identifier'] ?? $row['taxon_identifier'] ?? ''));
        $scientificNameIdentifier = trim((string) ($row[$prefix . '_scientific_name_identifier'] ?? $row['scientific_name_identifier'] ?? ''));
        $scientificName = trim((string) ($row[$prefix . '_scientific_name'] ?? $row[$prefix] ?? $row['scientific_name'] ?? ''));

        return [
            'taxon_identifier' => $taxonIdentifier,
            'scientific_name_identifier' => $scientificNameIdentifier,
            'scientific_name' => $scientificName,
            'scientific_name_authorship' => $row[$prefix . '_scientific_name_authorship'] ?? $row['scientific_name_authorship'] ?? null,
            'vernacular_name' => trim((string) ($row[$prefix . '_vernacular_name'] ?? $row['vernacular_name'] ?? $scientificName)),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeTaxonGroupRow(array $row): array
    {
        return [
            'external_key' => trim((string) ($row['taxon_group_external_key'] ?? $row['external_key'] ?? '')),
            'title' => trim((string) ($row['taxon_group'] ?? $row['title'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRecordingSchemeRow(array $row): array
    {
        return [
            'external_key' => trim((string) ($row['recording_scheme_external_key'] ?? $row['external_key'] ?? '')),
            'title' => trim((string) ($row['recording_scheme'] ?? $row['title'] ?? '')),
            'description' => trim((string) ($row['recording_scheme_description'] ?? $row['description'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeTaxaRow(array $row): array
    {
        return [
            'taxon_identifier' => (string) ($row['taxon_identifier'] ?? ''),
            'scientific_name_identifier' => (string) ($row['scientific_name_identifier'] ?? ''),
            'scientific_name' => (string) ($row['scientific_name'] ?? ''),
            'scientific_name_authorship' => $row['scientific_name_authorship'] ?? null,
            'vernacular_name' => (string) ($row['vernacular_name'] ?? ''),
            'taxon_group' => (string) ($row['taxon_group'] ?? ''),
            'taxon_group_external_key' => (string) ($row['taxon_group_external_key'] ?? ''),
            'recording_scheme' => (string) ($row['recording_scheme'] ?? ''),
            'recording_scheme_external_key' => (string) ($row['recording_scheme_external_key'] ?? ''),
            'conservation_status' => $row['conservation_status'] ?? null,
            'order_taxon_identifier' => (string) ($row['order_taxon_identifier'] ?? ''),
            'superfamily_taxon_identifier' => (string) ($row['superfamily_taxon_identifier'] ?? ''),
            'family_taxon_identifier' => (string) ($row['family_taxon_identifier'] ?? ''),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function uniqueRowsByKey(array $rows, string $key): array
    {
        $deduplicated = [];

        foreach ($rows as $row) {
            $identifier = trim((string) ($row[$key] ?? ''));

            if ($identifier === '') {
                continue;
            }

            $deduplicated[$identifier] = $row;
        }

        return array_values($deduplicated);
    }
}
