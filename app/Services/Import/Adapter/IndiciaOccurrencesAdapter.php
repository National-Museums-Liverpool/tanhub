<?php

namespace App\Services\Import\Adapter;

use CodeIgniter\HTTP\CURLRequest;
use RuntimeException;

/**
 * Fetches normalized occurrence records from an Indicia occurrences API endpoint.
 */
class IndiciaOccurrencesAdapter implements OccurrenceSourceAdapterInterface
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
        $endpoint = $this->resolveEndpoint();

        if ($endpoint === '') {
            throw new RuntimeException('Indicia endpoint is not configured. Set Config\\Import.indiciaWarehouseUrl or occurrenceSources.indicia.endpoint.');
        }

        $apiQuery = $this->buildApiQuery();
        $requestBody = $this->buildSearchBody($checkpoint, $limit);
        log_message('debug', 'Indicia request: ' . $endpoint . ' Query: ' . json_encode($apiQuery) . ' Body: ' . json_encode($requestBody));
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        $projectId = trim((string) ($this->config['project_id'] ?? ''));
        $username = trim((string) ($this->config['username'] ?? ''));
        $secret = trim((string) ($this->config['secret'] ?? ''));

        if ($projectId !== '') {
            $headers['X-Project-Id'] = $projectId;
        }

        if ($username !== '' && $secret !== '') {
            $headers['Authorization'] = "USER:$username:SECRET:$secret";
        }

        $response = $this->client->post($endpoint, [
            'headers' => $headers,
            'query' => $apiQuery,
            'body' => json_encode($requestBody, JSON_THROW_ON_ERROR),
            'http_errors' => false,
            'timeout' => $this->timeout,
        ]);
        log_message('debug', 'Indicia response: ' . $response->getStatusCode() . ' Body: ' . (string) $response->getBody());

        if ($response->getStatusCode() >= 400) {
            $body = trim((string) $response->getBody());
            $bodyPreview = $body === '' ? '' : ' Response: ' . substr($body, 0, 500);
            throw new RuntimeException('Indicia request failed with status ' . $response->getStatusCode() . '.' . $bodyPreview);
        }

        $payload = json_decode($response->getBody(), true);

        if (! is_array($payload)) {
            throw new RuntimeException('Indicia response was not valid JSON object/array.');
        }

        $records = $this->extractRecords($payload);
        $normalized = [];
        $lastCheckpointValue = $checkpoint;
        $checkpointField = (string) ($this->config['checkpoint_field'] ?? 'metadata.tracking');

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $normalizedRecord = $this->normalizeRecord($record);

            $checkpointValue = $this->stringFromPath($record, $checkpointField);

            if ($checkpointValue !== null) {
                $lastCheckpointValue = $checkpointValue;
                $normalizedRecord['_checkpoint'] = $lastCheckpointValue;
            }

            $normalized[] = $normalizedRecord;
        }

        $hasMore = count($records) >= $limit;

        if (isset($payload['has_more']) && is_bool($payload['has_more'])) {
            $hasMore = $payload['has_more'];
        }

        if (isset($payload['meta']) && is_array($payload['meta'])) {
            $returnedCount = (int) ($payload['meta']['count'] ?? count($records));
            $total = (int) ($payload['meta']['total'] ?? 0);
            $offset = (int) ($payload['meta']['offset'] ?? 0);
            $hasMore = $offset + $returnedCount < $total;
        }

        if (isset($payload['hits']['total'])) {
            $totalRaw = $payload['hits']['total'];
            $total = is_array($totalRaw) ? (int) ($totalRaw['value'] ?? 0) : (int) $totalRaw;
            $hasMore = count($records) < $total && count($records) >= $limit;
        }

        return new ImportPage($normalized, $lastCheckpointValue, $hasMore);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildApiQuery(): array
    {
        $query = (array) ($this->config['query'] ?? []);

        $projectId = trim((string) ($this->config['project_id'] ?? ''));

        if ($projectId !== '' && ! isset($query['proj_id'])) {
            $query['proj_id'] = $projectId;
        }

        return array_filter($query, static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * Fetch the Indicia IDs of taxon groups for a report filter param.
     *
     * @return string
     */
    private function getTaxonGroupIndiciaIdsAsReportParam(array $taxonGroups): array {
      // Load the taxon groups from the MySQL database and extract the Indicia
      // IDs for the configured groups.
      $db = db_connect();
      $taxonGroups = $db->table('taxon_groups')
        ->select('indicia_taxon_group_id')
        ->where('deleted_at', null)
        // Exclude groups only included to allow a complete taxonomic hierarchy
        // to be imported, but not intended for use in the app.
        ->where('implied', 0)
        ->whereIn('title', $taxonGroups)
        ->get()
        ->getResultArray();
      return array_column($taxonGroups, 'indicia_taxon_group_id');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSearchBody(?string $checkpoint, int $limit): array
    {
        $mustFilters = [];

        $taxonGroups = $this->normalisedListValues($this->config['taxon_groups'] ?? []);
        $taxonRanks = $this->normalisedListValues($this->config['taxon_ranks'] ?? []);
        $geographicRegions = $this->normalisedListValues($this->config['geographic_regions'] ?? []);
        $locationType = trim((string) ($this->config['geographic_region_location_type'] ?? ''));

        if ($taxonGroups !== []) {
            $mustFilters[] = [
                'terms' => ['taxon.group_id' => $this->getTaxonGroupIndiciaIdsAsReportParam($taxonGroups)],
            ];
        }

        if ($taxonRanks !== []) {
            $mustFilters[] = ['terms' => ['taxon.taxon_rank.keyword' => $taxonRanks]];
        }

        if ($geographicRegions !== []) {
            $mustFilters[] = [
                'nested' => [
                    'path' => 'location.higher_geography',
                    'query' => [
                        'bool' => [
                            'must' => [
                                ['terms' => ['location.higher_geography.name.keyword' => $geographicRegions]],
                                ['term' => ['location.higher_geography.type.keyword' => $locationType]],
                            ],
                        ],
                    ],
                ],
            ];
        }

        if ($checkpoint !== null && $checkpoint !== '') {
            $mustFilters[] = [
                'range' => [
                    'metadata.tracking' => [
                        'gt' => is_numeric($checkpoint) ? (int) $checkpoint : $checkpoint,
                    ],
                ],
            ];
        }

        $query = [
            'bool' => [
                'filter' => $mustFilters,
            ],
        ];

        return [
            'size' => max(1, $limit),
            'track_total_hits' => true,
            'sort' => [
                ['metadata.tracking' => ['order' => 'asc']],
            ],
            'query' => $query,
        ];
    }

    private function resolveEndpoint(): string
    {
        $configuredEndpoint = trim((string) ($this->config['endpoint'] ?? ''));

        if ($configuredEndpoint !== '' && preg_match('#^https?://#i', $configuredEndpoint) === 1) {
            return $configuredEndpoint;
        }

        $warehouseUrl = rtrim((string) ($this->config['warehouse_url'] ?? ''), '/');

        if ($warehouseUrl === '') {
            return '';
        }

        $path = $configuredEndpoint !== ''
            ? $configuredEndpoint
            : (string) ('/index.php/services/rest/' . ($this->config['es_endpoint'] ?? 'occurrences') . '/_search/');

        $path = '/' . ltrim($path, '/');

        return $warehouseUrl . $path;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractRecords(array $payload): array
    {
        $recordsKey = (string) ($this->config['records_key'] ?? 'occurrences');

        if (isset($payload[$recordsKey]) && is_array($payload[$recordsKey])) {
            return $this->normaliseExtractedRecords($payload[$recordsKey]);
        }

        if (isset($payload['records']) && is_array($payload['records'])) {
            return $this->normaliseExtractedRecords($payload['records']);
        }

        if (isset($payload['data']) && is_array($payload['data'])) {
            return $this->normaliseExtractedRecords($payload['data']);
        }

        if (isset($payload['hits']['hits']) && is_array($payload['hits']['hits'])) {
            return $this->normaliseExtractedRecords($payload['hits']['hits']);
        }

        if (array_is_list($payload)) {
            return $this->normaliseExtractedRecords($payload);
        }

        return [];
    }

    /**
     * @param array<int, mixed> $records
     * @return array<int, array<string, mixed>>
     */
    private function normaliseExtractedRecords(array $records): array
    {
        $normalised = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            // Elasticsearch can wrap docs as {_id, _source: {...}}.
            if (isset($record['_source']) && is_array($record['_source'])) {
                $source = $record['_source'];
                $source['_id'] = (string) ($record['_id'] ?? ($source['_id'] ?? ''));
                $normalised[] = $source;
                continue;
            }

            $normalised[] = $record;
        }

        return $normalised;
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function normalizeRecord(array $record): array
    {
        $gridRef = (string) ($this->stringFromPath($record, 'location.output_sref'));
        $gridRef2km = $this->calculateTetrad($gridRef);
        return [
            'remote_id' => (string) ($record['_id']
                ?? $this->stringFromPath($record, 'occurrence.source_system_key')
                ?? ($record['id'] ?? $record['occurrence_id'] ?? '')),
            'source_name' => (string) ($this->stringFromPath($record, 'metadata.website.title')
                ?? $record['source_name']
                ?? 'Indicia'),
            // Indicia ES data doesn't currently hold the organism key.
            'taxon_identifier' => null,
            // So we can use the accepted name TVK as a unique ID instead.
            'scientific_name_identifier' => (string) ($this->stringFromPath($record, 'taxon.accepted_taxon_id')
                ?? $this->stringFromPath($record, 'taxon.species_taxon_id')
                ?? $record['taxon_identifier']
                ?? ''),
            'given_name_identifier' => (string) ($this->stringFromPath($record, 'taxon.taxon_id')
                ?? $record['given_name_identifier']
                ?? $this->stringFromPath($record, 'taxon.accepted_taxon_id')
                ?? ''),
            'from_date' => $this->valueFromPath($record, 'event.date_start') ?? $record['from_date'] ?? $record['event_date'] ?? null,
            'to_date' => $this->valueFromPath($record, 'event.date_end') ?? $record['to_date'] ?? null,
            'grid_ref' => $gridRef,
            'grid_ref_2km' => $gridRef2km,
            'locality' => $this->valueFromPath($record, 'location.verbatim_locality') ?? $record['locality'] ?? null,
            'recorded_by' => $this->valueFromPath($record, 'event.recorded_by') ?? $record['recorded_by'] ?? null,
            'identified_by' => $this->valueFromPath($record, 'identification.identified_by') ?? $record['identified_by'] ?? null,
            'identification_verification_status' => $this->valueFromPath($record, 'identification.verification_status') ?? $record['identification_verification_status'] ?? 'UN',
            'sex' => $this->valueFromPath($record, 'occurrence.sex') ?? $record['sex'] ?? null,
            'life_stage' => $this->valueFromPath($record, 'occurrence.life_stage') ?? $record['life_stage'] ?? null,
            'organism_quantity' => $this->valueFromPath($record, 'occurrence.organism_quantity')
                ?? $this->valueFromPath($record, 'occurrence.individual_count')
                ?? $record['organism_quantity']
                ?? null,
        ];
    }

    /**
     * Convert an OSGB grid reference to a DINTY format 2km reference.
     *
     * @param string $gridRef
     * @return string|null
     */
    private function calculateTetrad(string $gridRef): ?string
    {
        $gridRef = strtoupper(preg_replace('/\s+/', '', trim($gridRef)) ?? '');

        if ($gridRef === '' || preg_match('/^[A-Z]{2}\d+$/', $gridRef) !== 1) {
            return null;
        }

        $letters = substr($gridRef, 0, 2);
        $digits = substr($gridRef, 2);

        if (strlen($digits) < 4 || strlen($digits) % 2 !== 0) {
            return null;
        }

        if (str_contains($letters, 'I')) {
            return null;
        }

        $precisionDigits = strlen($digits) / 2;
        $scale = 10 ** (5 - $precisionDigits);
        $hectadScale = 10 ** ($precisionDigits - 1);

        $eastingDigits = (int) substr($digits, 0, $precisionDigits);
        $northingDigits = (int) substr($digits, $precisionDigits);

        $eastingHectad = intdiv($eastingDigits, $hectadScale);
        $northingHectad = intdiv($northingDigits, $hectadScale);

        $eastingWithinHectad = ($eastingDigits % $hectadScale) * $scale;
        $northingWithinHectad = ($northingDigits % $hectadScale) * $scale;

        $tetradX = intdiv($eastingWithinHectad, 2000);
        $tetradY = intdiv($northingWithinHectad, 2000);

        if ($tetradX < 0 || $tetradX > 4 || $tetradY < 0 || $tetradY > 4) {
            return null;
        }

        $tetradLetters = 'ABCDEFGHIJKLMNPQRSTUVWXYZ';
        $tetradIndex = ($tetradY * 5) + $tetradX;

        return $letters . $eastingHectad . $northingHectad . $tetradLetters[$tetradIndex];
    }

    /**
     * @param mixed $value
     */
    private function normalisedListValues($value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $normalised = [];

        foreach ($values as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $string = trim((string) $item, " \t\n\r\0\x0B\"'");

            if ($string === '') {
                continue;
            }

            $normalised[] = $string;
        }

        return array_values(array_unique($normalised));
    }

    /**
     * @param array<string, mixed> $record
     */
    private function valueFromPath(array $record, string $path): mixed
    {
        $segments = explode('.', $path);
        $value = $record;

        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function stringFromPath(array $record, string $path): ?string
    {
        $value = $this->valueFromPath($record, $path);

        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
