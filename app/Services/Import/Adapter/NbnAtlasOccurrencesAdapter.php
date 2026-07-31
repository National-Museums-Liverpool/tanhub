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

        $query = $this->buildQuery($checkpoint, $limit);
        $requestUrl = $this->buildRequestUrl($endpoint, $query);
        log_message('debug', 'NBN request URL: ' . $requestUrl);
        $response = $this->client->get($requestUrl, [
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
        $startIndex = $this->intFromAny([
            $payload['startIndex'] ?? null,
            $payload['start'] ?? null,
            $query['start'] ?? null,
        ]) ?? 0;
        $pageSize = $this->intFromAny([
            $payload['pageSize'] ?? null,
            $query['pageSize'] ?? null,
            $limit,
        ]) ?? max(1, $limit);
        $nextOffset = $startIndex + count($records);
        $totalRecords = $this->intFromAny([$payload['totalRecords'] ?? null]);

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $normalized[] = $this->normalizeRecord($record);
        }

        $hasMore = count($records) >= $pageSize;

        if ($totalRecords !== null) {
            $hasMore = $nextOffset < $totalRecords;
        }

        $nextCheckpoint = (string) $nextOffset;

        return new ImportPage($normalized, $nextCheckpoint, $hasMore);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildQuery(?string $checkpoint, int $limit): array
    {
        $query = (array) ($this->config['query'] ?? []);
        $query['q'] = trim((string) ($query['q'] ?? '*:*'));
        $query['pageSize'] = max(1, $limit);
        $query['sort'] = 'occurrenceID';
        $query['start'] = $this->normaliseStartCheckpoint($checkpoint);

        $filters = [];
        $regionFilter = $this->buildOrFilter('cl254', $this->normalisedListValues($this->config['geographic_regions'] ?? []));

        if ($regionFilter !== null) {
            $filters[] = $regionFilter;
        }

        if ($this->config['min_taxon_rank_id'] ?? null) {
            $filters[] = 'taxonRankID:[' . (int) $this->config['min_taxon_rank_id'] . ' TO *]';
        }

        foreach ($this->configuredNbnFqClauses() as $configuredClause) {
            $filters[] = $configuredClause;
        }

        $filters[] = '-(user_assertions:"50005" OR user_assertions:"50006" OR user_assertions:"50001")';
        $query['fq'] = implode('&fq=', $filters);

        return $query;
    }

    /**
     * Build a URL with each NBN fq filter represented as its own parameter.
     *
     * @param string               $endpoint NBN API endpoint.
     * @param array<string, mixed> $query    Request query values.
     *
     * @return string Request URL.
     */
    private function buildRequestUrl(string $endpoint, array $query): string
    {
        $filterString = (string) ($query['fq'] ?? '');
        unset($query['fq']);

        $queryString = http_build_query($query);
        $filters = explode('&fq=', $filterString);

        foreach ($filters as $filter) {
            if (trim($filter) === '') {
                continue;
            }

            $queryString .= ($queryString === '' ? '' : '&') . 'fq=' . rawurlencode($filter);
        }

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . $queryString;
    }

    /**
     * @return array<int, string>
     */
    private function configuredNbnFqClauses(): array
    {
        $configured = trim((string) ($this->config['nbn_filter_query'] ?? $this->config['filter_query'] ?? ''));

        if ($configured === '') {
            return [];
        }

        // Accept either a full query-fragment (fq=...&fq=...) or a single raw fq clause.
        if (! str_contains($configured, '&') && ! str_starts_with($configured, 'fq=')) {
            return [$configured];
        }

        $clauses = [];

        foreach (explode('&', $configured) as $part) {
            $segment = trim($part);

            if ($segment === '') {
                continue;
            }

            if (str_starts_with($segment, 'fq=')) {
                $segment = substr($segment, 3);
            }

            $segment = trim(urldecode($segment));

            if ($segment === '') {
                continue;
            }

            $clauses[] = $segment;
        }

        return array_values(array_unique($clauses));
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
        $gridRefSystem = (string) ($record['grid_ref_system']
            ?? $record['gridReferenceSystem']
            ?? $record['spatialReferenceSystem']
            ?? $record['coordinateSystem']
            ?? '');
        $coordinateUncertainty = $this->numberFromAny([
            $record['coordinate_uncertainty_in_meters'] ?? null,
            $record['coordinateUncertaintyInMeters'] ?? null,
            $record['uncertaintyInMeters'] ?? null,
            $record['coordinateUncertainty'] ?? null,
        ]);

        if ($gridRef2km === '' && $gridRef !== '') {
            $gridRef2km = strtoupper(substr(str_replace(' ', '', $gridRef), 0, 5));
        }

        $remoteId = trim((string) ($record['uuid'] ?? ''));

        $taxonConceptId = trim((string) ($record['taxonConceptID'] ?? $record['taxonConceptId'] ?? ''));

        if ($taxonConceptId === '') {
            $taxonConceptId = trim((string) ($record['scientificNameID'] ?? ''));
        }

        return [
            'remote_id' => $remoteId,
            'occurrence_id' => trim((string) ($record['occurrenceID'] ?? '')),
            'source_name' => (string) ($record['dataResourceName'] ?? $record['source_name'] ?? 'NBN Atlas'),
            'data_provider_name' => (string) ($record['dataProviderName'] ?? $record['data_provider_name'] ?? ''),
            'scientific_name_identifier' => $taxonConceptId,
            'given_name_identifier' => $taxonConceptId,
            'from_date' => $record['from_date'] ?? $record['eventDate'] ?? null,
            'to_date' => $record['to_date'] ?? null,
            'grid_ref' => $gridRef,
            'grid_ref_system' => $gridRefSystem,
            'grid_ref_2km' => $gridRef2km,
            'locality' => $record['locality'] ?? null,
            'recorded_by' => $record['recorded_by'] ?? $record['recordedBy'] ?? null,
            'identified_by' => $record['identified_by'] ?? $record['identifiedBy'] ?? null,
            'identification_verification_status' => $record['identification_verification_status'] ?? $record['identificationVerificationStatus'] ?? 'UN',
            'sex' => $record['sex'] ?? null,
            'life_stage' => $record['life_stage'] ?? null,
            'organism_quantity' => $record['organism_quantity'] ?? null,
            'latitude' => $record['decimalLatitude'] ?? null,
            'longitude' => $record['decimalLongitude'] ?? null,
            'coordinate_uncertainty_in_meters' => $coordinateUncertainty,
            'blocked' => (bool) ($record['blocked'] ?? false),
            'blocked_reason' => $record['blocked_reason'] ?? null,
        ];
    }

    /**
     * @param array<int, string> $values
     */
    private function buildOrFilter(string $field, array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        $escapedValues = array_map(fn (string $value): string => '"' . $this->escapeFilterValue($value) . '"', $values);

        return $field . ':(' . implode(' OR ', $escapedValues) . ')';
    }

    private function escapeFilterValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    /**
     * @param mixed $value
     */
    private function normaliseStartCheckpoint($value): int
    {
        if (! is_scalar($value)) {
            return 0;
        }

        $string = trim((string) $value);

        if ($string === '' || ! ctype_digit($string)) {
            return 0;
        }

        return max(0, (int) $string);
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalisedListValues($value): array
    {
        $values = is_array($value) ? $value : explode(',', (string) $value);
        $normalised = [];

        foreach ($values as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $string = trim((string) $item);

            if ($string === '') {
                continue;
            }

            $normalised[] = $string;
        }

        return array_values(array_unique($normalised));
    }

    /**
     * @param array<int, mixed> $values
     */
    private function intFromAny(array $values): ?int
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $string = trim((string) $value);

            if ($string === '' || ! ctype_digit($string)) {
                continue;
            }

            return (int) $string;
        }

        return null;
    }

    /**
     * @param array<int, mixed> $values
     */
    private function numberFromAny(array $values): ?float
    {
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $string = trim((string) $value);

            if ($string === '' || ! is_numeric($string)) {
                continue;
            }

            return (float) $string;
        }

        return null;
    }
}
