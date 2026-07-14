<?php

namespace App\Services\Import\Adapter;

use CodeIgniter\HTTP\CURLRequest;
use InvalidArgumentException;
use RuntimeException;

/**
 * Fetches normalized import rows from Indicia report endpoints.
 */
class IndiciaImportAdapter implements ImportSourceAdapterInterface
{
    /**
     * @var \Config\Import|array<string, mixed>
     */
    private readonly mixed $importConfig;

    /**
     * @var array<string, mixed>
     */
    private readonly array $sourceConfig;

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
        'taxon_ranks',
        'geographic_regions',
    ];

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly CURLRequest $client,
        \Config\Import $config,
        array $dataSourceConfig,
    ) {
        $this->importConfig = $config;
        $this->sourceConfig = $dataSourceConfig;
    }

    /**
     * Fetch one normalized import batch.
     */
    public function fetchBatch(string $entity, int $limit, int $offset): ImportBatch
    {
        $entityKey = strtolower($entity);

        if (! in_array($entityKey, self::SUPPORTED_ENTITIES, true)) {
            throw new InvalidArgumentException('Unsupported import entity: ' . $entity);
        }

        $batchLimit = max(1, $limit);
        $batchOffset = max(0, $offset);

        $rawRows = $this->fetchImportData($entityKey, $batchLimit, $batchOffset);
        $normalisedRows = $this->normaliseRows($entityKey, $rawRows);

        return new ImportBatch(
            entity: $entityKey,
            offset: $batchOffset,
            rows: $normalisedRows,
            nextOffset: $batchOffset + count($rawRows),
            hasMore: count($rawRows) >= $batchLimit,
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchImportData(string $entity, int $limit, int $offset): array
    {
        $url = rtrim((string) ($this->importConfig->indiciaWarehouseUrl ?? ''), '/');

        if ($url === '') {
            throw new RuntimeException('Indicia import source URL is not configured.');
        }

        switch ($entity) {
            case 'recording_schemes':
            case 'taxon_groups':
            case 'taxa':
            case 'taxon_ranks':
            case 'geographic_regions':
                $report = $entity;
                break;

            default:
                throw new InvalidArgumentException('Unsupported import entity: ' . $entity);
        }

        $endpoint = "$url/index.php/services/rest/reports/projects/tanhub/$report.xml";
        $query = [
            'proj_id' => (string) ($this->importConfig->indiciaProjId ?? ''),
            'taxon_list_id' => (int) ($this->importConfig->indiciaTaxonListId ?? 0),
            'limit' => $limit,
            'offset' => $offset,
        ];
        if ($report === 'taxon_ranks' || $report === 'taxa') {
            $query['taxon_ranks'] = $this->getConfigListAsReportParam('taxonRanks');
        }
        if ($report === 'taxon_groups') {
            $query['taxon_groups'] = $this->getConfigListAsReportParam('taxonGroups');
        }
        if ($report === 'taxa') {
            $query['taxon_group_ids'] = $this->getTaxonGroupIndiciaIdsAsReportParam();
        }
        if ($report === 'geographic_regions') {
            $query['geographic_regions'] = $this->getConfigListAsReportParam('geographicRegions');
            $query['location_type'] = trim((string) ($this->importConfig->geographicRegionLocationType ?? ''));
        }

        $user = (string) ($this->importConfig->indiciaUsername ?? '');
        $secret = (string) ($this->importConfig->indiciaSecret ?? '');
        $response = $this->client->get($endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => "USER:$user:SECRET:$secret",
            ],
            'query' => $query,
            'http_errors' => false,
            'timeout' => $this->importConfig->httpTimeout ?? 30,
        ]);

        if ($response->getStatusCode() >= 400) {
            throw new RuntimeException('Indicia request failed with status ' . $response->getStatusCode() . ' for entity ' . $entity);
        }

        $payload = json_decode($response->getBody(), true);

        if (! is_array($payload)) {
            throw new RuntimeException('Indicia response was not valid JSON for entity ' . $entity);
        }

        return $this->extractRecords($payload);
    }

    /**
     * Fetch the Indicia IDs of taxon groups for a report filter param.
     *
     * @return string
     */
    private function getTaxonGroupIndiciaIdsAsReportParam(): string {
      // Load the taxon groups from the MySQL database and extract the Indicia
      // IDs for the configured groups.
      $db = db_connect();
      $taxonGroups = $db->table('taxon_groups')
        ->select('indicia_taxon_group_id')
        ->where('deleted_at', null)
        ->whereIn('title', $this->importConfig->taxonGroups ?? [])
        ->get()
        ->getResultArray();
      return implode(',', array_column($taxonGroups, 'indicia_taxon_group_id'));
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normaliseRows(string $entity, array $rows): array
    {
        return match ($entity) {
            'taxa' => array_map(fn (array $row): array => $this->normaliseTaxaRow($row), $rows),
            'recording_schemes' => $this->uniqueRowsByKey(array_map(fn (array $row): array => $this->normaliseRecordingSchemeRow($row), $rows), 'external_key'),
            'taxon_groups' => $this->uniqueRowsByKey(array_map(fn (array $row): array => $this->normaliseTaxonGroupRow($row), $rows), 'external_key'),
            'taxon_ranks' => $this->uniqueRowsByKey(array_map(fn (array $row): array => $this->normaliseTaxonRankRow($row), $rows), 'rank'),
            'geographic_regions' => $this->uniqueRowsByKey(array_map(fn (array $row): array => $this->normaliseGeographicRegionRow($row), $rows), 'higher_geography_identifier'),
            default => [],
        };
    }

    /**
     * Convert the list of ranks or groups to a CSV style param for an Indicia report.
     *
     * @return string
     */
    private function getConfigListAsReportParam(string $configName): string
    {
        $configured = $this->importConfig->$configName ?? [];

        $values = is_array($configured) ? $configured : explode(',', (string) $configured);
        $normalised = [];

        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $name = trim((string) $value, " \t\n\r\0\x0B\"'");

            if ($name === '') {
                continue;
            }

            $normalised[] = "'" . str_replace("'", "''", $name) . "'";
        }

        return implode(',', $normalised);
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
     * Cleanup a taxon group row read from Indicia.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normaliseTaxonGroupRow(array $row): array
    {
        return [
            'external_key' => trim((string) ($row['taxon_group_external_key'] ?? $row['external_key'] ?? '')),
            'indicia_taxon_group_id' => (int) $row['id'],
            'title' => trim((string) ($row['taxon_group'] ?? $row['title'] ?? '')),
        ];
    }

    /**
     * Cleanup a recording scheme row read from Indicia.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normaliseRecordingSchemeRow(array $row): array
    {
        return [
            'external_key' => trim((string) ($row['recording_scheme_external_key'] ?? $row['external_key'] ?? '')),
            'title' => trim((string) ($row['recording_scheme'] ?? $row['title'] ?? '')),
            'description' => trim((string) ($row['recording_scheme_description'] ?? $row['description'] ?? '')),
        ];
    }

    /**
     * Cleanup a taxon rank row read from Indicia.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normaliseTaxonRankRow(array $row): array
    {
        return [
            'rank' => trim((string) ($row['rank'] ?? $row['name'] ?? $row['title'] ?? '')),
            'abbr' => trim((string) ($row['abbr'] ?? $row['external_key'] ?? $row['slug'] ?? '')),
            'sort_order' => (int) ($row['sort_order'] ?? $row['sort'] ?? $row['weight'] ?? 0),
        ];
    }

    /**
     * Cleanup a taxon row read from Indicia.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normaliseTaxaRow(array $row): array
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
            'taxon_rank' => (string) ($row['taxon_rank'] ?? ''),
            'higher_taxa' => json_decode((string) ($row['higher_taxa'] ?? '[]')),
        ];
    }

    /**
     * Cleanup a geographic region row read from Indicia.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normaliseGeographicRegionRow(array $row): array
    {
        return [
            'higher_geography_identifier' => (int) ($row['higher_geography_identifier'] ?? $row['location_code'] ?? $row['code'] ?? $row['id'] ?? 0),
            'higher_geography' => trim((string) ($row['higher_geography'] ?? $row['name'] ?? $row['title'] ?? '')),
            'location_type' => trim((string) ($row['location_type'] ?? $this->importConfig->geographicRegionLocationType ?? '')),
            'data_source_abbr' => strtoupper((string) ($this->sourceConfig['abbr'] ?? 'IREC')),
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