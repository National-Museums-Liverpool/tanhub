<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use RuntimeException;

/**
 * Import pipeline configuration.
 */
class Import extends BaseConfig
{
    /**
     * @var int
     */
    public int $defaultLimit = 5000;

    /**
     * @var int
     */
    public int $defaultPageSize = 200;

    /**
     * Number of successful occurrence runs allowed between derived runs.
     *
     * @var int
     */
    public int $occurrenceRunsPerDerivedRun = 2;

    /**
     * Number of successful occurrence runs allowed between derived runs while
     * at least one occurrence source is incomplete.
     *
     * @var int
     */
    public int $incompleteOccurrenceRunsPerDerivedRun = 10;

    /**
     * @var int
     */
    public int $httpTimeout = 30;

    /**
     * Legacy UI timeout retained for configuration compatibility.
     *
     * Recovery now requires the exclusive import lock, not an elapsed timeout.
     *
     * @var int
     */
    public int $uiTaskStaleAfter = 3600;

    /**
    * MySQL advisory lock name used to serialize imports.
     *
     * @var string
     */
    public string $importLockName = 'tanhub:import';

    /**
    * File used to prevent overlapping imports on this host.
     *
     * @var string
     */
    public string $importLockFile = WRITEPATH . 'import.lock';

    /**
     * Indicia Warehouse base URL.
     *
     * @var string
     */
    public string $indiciaWarehouseUrl = '';

    /**
     * Indicia REST API project identifier.
     *
     * @var string
     */
    public string $indiciaProjId = '';

    /**
     * Indicia taxon list identifier to import.
     *
     * @var int
     */
    public int $indiciaTaxonListId = 0;

    /**
     * Indicia REST API username.
     *
     * @var string
     */
    public string $indiciaUsername = '';

    /**
     * Indicia REST API authentication secret.
     *
     * @var string
     */
    public string $indiciaSecret = '';

    /**
     * Endpoint used for connection to ES service for occurrence data.
     *
     * Endpoint must be configured in Indicia's REST API.
     *
     * @var string
     */
    public string $indiciaOccurrencesEsEndpoint = 'es';

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $occurrenceSources = [
        'nbn' => [
            'abbr' => 'NBN',
            'endpoint' => 'https://records-ws.nbnatlas.org/occurrences/search',
            'records_key' => 'occurrences',
            'filter_query' => '',
            'query' => [],
        ],
        'indicia' => [
            'abbr' => 'IREC',
            'records_key' => 'data',
            'endpoint' => '',
            'checkpoint_param' => 'since',
            'checkpoint_field' => 'metadata.tracking',
            'query' => [],
        ],
    ];

    /**
     * @var array<string, array<string, mixed>>
     */
    public array $taxonomySources = [
        'indicia' => [
            'abbr' => 'IREC',
            'checkpoint_param' => 'since',
            'checkpoint_field' => 'lastModified',
            'query' => [],
        ],
    ];

    /**
     * Taxonomic levels we allow reporting at.
     *
     * @var array|string
     */
    public array|string $taxonRanks = [
        'Order',
        'Superfamily',
        'Family',
        'Genus',
        'Species',
    ];

    /**
     * Override automatic nearest-ancestor reporting rank mappings.
     *
     * Unlisted non-reporting ranks map to the nearest configured ancestor
     * according to taxon rank sort order. Add exceptions here when a rank
     * must map to a different reporting level, including a lower level.
     *
     * @var array<string, string>
     */
    public array $taxonRankMappings = [
        'Species aggregate' => 'Species',
    ];

    /**
     * Taxonomic groups we allow reporting at.
     *
     * @var array|string
     */
    public array|string $taxonGroups = [
        'insect - moth',
        'insect - caddis fly (Trichoptera)',
    ];

    /**
     * Minimum indicia taxon rank, defaults to 230 (Genus).
     *
     * @var int
     */
    public int $indiciaMinTaxonRankSortOrder = 230;

    /**
     * Minimum NBN Atlas taxon rank, defaults to 6000 (Genus).
     *
     * @var int
     */
    public int $nbnMinTaxonRankId = 6000;

    /**
     * Optional extra NBN Atlas fq filter query.
     *
     * Supports forms like:
     * - kingdom:Animalia
     * - fq=kingdom:Animalia&fq=-phylum:Chordata&fq=-order:Lepidoptera
     *
     * @var string
     */
    public string $nbnApiFilterQuery = '';

    /**
     * Installation-time geography groups, keyed by a stable checkpoint identity.
     *
     * Names are stored unencoded. NBN uses irecordName unless nbnName overrides it.
     *
     * @var array<string, array{indiciaLocationType: string, nbnField: string,
     *     locations: list<array{irecordName: string, nbnName?: string}>}>
     */
    public array $geographicRegions = [
        'vice-counties' => [
            'indiciaLocationType' => 'Vice County',
            'nbnField' => 'cl254',
            'locations' => [
                ['irecordName' => 'Cheshire'],
                ['irecordName' => 'South Lancashire'],
                ['irecordName' => 'West Lancashire'],
            ],
        ],
    ];

    /**
     * Maximum coordinate uncertainty in metres.
     *
     * Default is to drop records at lower than 10km precision. US spelling to match Darwin Core.
     * Set to zero to disable this limit.
     *
     * @var int
     */
    public int $maximumCoordinateUncertaintyInMeters = 10000;

    /**
     * Load and validate installation-time array overrides from .env.
     */
    public function __construct()
    {
        parent::__construct();
        $configuredOccurrenceRunsPerDerivedRun = $this->validateInt(env('import.occurrenceRunsPerDerivedRun'));
        if ($configuredOccurrenceRunsPerDerivedRun !== null) {
            $this->occurrenceRunsPerDerivedRun = max(1, $configuredOccurrenceRunsPerDerivedRun);
        }
        $configuredIncompleteOccurrenceRunsPerDerivedRun = $this->validateInt(env('import.incompleteOccurrenceRunsPerDerivedRun'));
        if ($configuredIncompleteOccurrenceRunsPerDerivedRun !== null) {
            $this->incompleteOccurrenceRunsPerDerivedRun = max(1, $configuredIncompleteOccurrenceRunsPerDerivedRun);
        }
        $configuredRanks = env('import.taxonRanks');
        if (is_string($configuredRanks) && $configuredRanks !== '') {
            // Cleanup stray characters or whitespace.
            $configuredRanks = preg_replace('/[^a-z,]+/i', '', $configuredRanks);
            $this->taxonRanks = array_map('trim', explode(',', $configuredRanks));
            log_message('info', 'Configured taxon ranks overriden: ' . $configuredRanks);
        }
        $this->assertSpeciesRankConfigured();
        $configuredRankMappings = env('import.taxonRankMappings');
        if (is_string($configuredRankMappings) && trim($configuredRankMappings) !== '') {
            $decodedRankMappings = json_decode($configuredRankMappings, true);

            if (! is_array($decodedRankMappings)) {
                throw new RuntimeException('Config Import: import.taxonRankMappings must be a JSON object.');
            }

            $this->taxonRankMappings = $this->normaliseTaxonRankMappings($decodedRankMappings);
            log_message('info', 'Configured taxon rank mappings overridden.');
        } else {
            $this->taxonRankMappings = $this->normaliseTaxonRankMappings($this->taxonRankMappings);
        }
        $configuredTaxonGroups = env('import.taxonGroups');
        if (is_string($configuredTaxonGroups) && $configuredTaxonGroups !== '') {
            $this->taxonGroups = array_map('trim', str_getcsv($configuredTaxonGroups));
            log_message('info', 'Configured taxon groups overriden: ' . $configuredTaxonGroups);
        }
        $configuredGeographicRegions = env('import.geographicRegions');
        if (is_string($configuredGeographicRegions) && trim($configuredGeographicRegions) !== '') {
            $decoded = json_decode($configuredGeographicRegions, true);
            if (! is_array($decoded)) {
                throw new RuntimeException(
                    'Your import configuration needs updating before imports can run. Replace the CSV '
                    . 'value for import.geographicRegions with the grouped JSON format. See '
                    . 'docs/configuration-reference.md#geographic-regions.',
                );
            }
            $this->geographicRegions = $decoded;
        }
        $legacyType = env('import.geographicRegionLocationType');
        if (is_string($legacyType) && trim($legacyType) !== '') {
            throw new RuntimeException(
                'Your import configuration needs updating before imports can run. Remove '
                . 'import.geographicRegionLocationType and replace the CSV value for '
                . 'import.geographicRegions with the grouped JSON format. Put each Indicia '
                . 'location type in its group\'s indiciaLocationType property. See '
                . 'docs/configuration-reference.md#geographic-regions.',
            );
        }
        $this->geographicRegions = $this->normaliseGeographicRegions($this->geographicRegions);
        $configuredIndiciaMinTaxonRankSortOrder = env('import.indiciaMinTaxonRankSortOrder');
        $validIndiciaMinTaxonRankSortOrder = $this->validateInt($configuredIndiciaMinTaxonRankSortOrder);
        if ($validIndiciaMinTaxonRankSortOrder !== null) {
            $this->indiciaMinTaxonRankSortOrder = $validIndiciaMinTaxonRankSortOrder;
            log_message('info', 'Configured indicia minimum taxon rank sort order overriden: ' . $this->indiciaMinTaxonRankSortOrder);
        }
        $configuredNbnMinTaxonRankId = env('import.nbnMinTaxonRankId');
        $validNbnMinTaxonRankId = $this->validateInt($configuredNbnMinTaxonRankId);
        if ($validNbnMinTaxonRankId !== null) {
            $this->nbnMinTaxonRankId = $validNbnMinTaxonRankId;
            log_message('info', 'Configured NBN minimum taxon rank ID overriden: ' . $this->nbnMinTaxonRankId);
        }
        $configuredNbnApiFilterQuery = env('import.nbnApiFilterQuery');
        if (is_string($configuredNbnApiFilterQuery)) {
            $this->nbnApiFilterQuery = trim($configuredNbnApiFilterQuery);
            if ($this->nbnApiFilterQuery !== '') {
                log_message('info', 'Configured NBN API filter query overriden: ' . $this->nbnApiFilterQuery);
            }
        }
        $configuredUiTaskStaleAfter = $this->validateInt(env('import.uiTaskStaleAfter'));
        if ($configuredUiTaskStaleAfter !== null && $configuredUiTaskStaleAfter > 0) {
            $this->uiTaskStaleAfter = $configuredUiTaskStaleAfter;
        }
    }

    /**
     * Validate geography groups before constructing source filters or progress keys.
     *
     * @param array<mixed> $groups Decoded installation configuration.
     *
     * @return array<string, array{indiciaLocationType: string, nbnField: string,
     *     locations: list<array{irecordName: string, nbnName?: string}>}>
     *
     * @throws RuntimeException When the scope is empty, ambiguous or malformed.
     */
    private function normaliseGeographicRegions(array $groups): array
    {
        if ($groups === [] || array_is_list($groups)) {
            throw new RuntimeException('Config Import: import.geographicRegions must be a non-empty JSON object of geography groups.');
        }

        $seen = [];
        foreach ($groups as $key => &$group) {
            // Leave room for the longest task prefix in import_offsets.source_key (64).
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9-]{0,28}$/D', $key)
                || ! is_array($group)
                || array_diff(array_keys($group), ['indiciaLocationType', 'nbnField', 'locations']) !== []
                || ! is_string($group['indiciaLocationType'] ?? null)
                || trim($group['indiciaLocationType']) === ''
                || ! is_string($group['nbnField'] ?? null)
                || ! preg_match('/^cl[0-9]+$/D', $group['nbnField'])
                || ! is_array($group['locations'] ?? null)
                || ! array_is_list($group['locations'])
                || $group['locations'] === []) {
                throw new RuntimeException('Config Import: invalid import.geographicRegions group ' . (string) $key . '. Expected indiciaLocationType, nbnField and a non-empty locations list.');
            }

            $group['indiciaLocationType'] = trim($group['indiciaLocationType']);
            foreach ($group['locations'] as &$location) {
                if (! is_array($location)
                    || array_diff(array_keys($location), ['irecordName', 'nbnName']) !== []
                    || ! is_string($location['irecordName'] ?? null)
                    || trim($location['irecordName']) === ''
                    || (array_key_exists('nbnName', $location)
                        && (! is_string($location['nbnName']) || trim($location['nbnName']) === ''))) {
                    throw new RuntimeException('Config Import: each geography location requires irecordName and an optional non-empty nbnName.');
                }
                $location['irecordName'] = trim($location['irecordName']);
                if (isset($location['nbnName'])) {
                    $location['nbnName'] = trim($location['nbnName']);
                }
                $identity = $group['indiciaLocationType'] . "\0" . $location['irecordName'];
                if (isset($seen[$identity])) {
                    throw new RuntimeException('Config Import: duplicate geography location ' . $location['irecordName'] . '.');
                }
                $seen[$identity] = true;
            }
            unset($location);
        }
        unset($group);

        return $groups;
    }

    /**
     * Validate and normalize an integer configuration value.
     *
     * @param mixed $value Configuration value to validate.
     *
     * @return int|null Normalized integer, or null when invalid.
     */
    private function validateInt($value): ?int
    {
        if (is_scalar($value)) {
            $value = trim((string) $value);
            if (preg_match('/^-?\d+$/', $value) === 1) {
                return (int) $value;
            }
        }
        return null;
    }

    /**
     * Ensure taxon ranks contain Species so species_id dynamic columns are always present.
     */
    private function assertSpeciesRankConfigured(): void
    {
        $ranks = $this->taxonRanks;
        $ranks = is_array($ranks) ? $ranks : explode(',', (string) $ranks);

        foreach ($ranks as $rank) {
            if (! is_scalar($rank)) {
                continue;
            }

            if (strcasecmp(trim((string) $rank), 'Species') === 0) {
                return;
            }
        }

        throw new RuntimeException('Config Import: import.taxonRanks must include Species.');
    }

    /**
     * Normalise and validate exceptional taxon rank mappings.
     *
     * @param array<mixed> $mappings Raw source-to-reporting rank mappings.
     * @return array<string, string> Validated mappings keyed by source rank.
     */
    private function normaliseTaxonRankMappings(array $mappings): array
    {
        $reportingRanks = $this->taxonRanks;
        $reportingRanks = is_array($reportingRanks) ? $reportingRanks : explode(',', (string) $reportingRanks);
        $reportingRankNames = [];

        foreach ($reportingRanks as $rank) {
            if (is_scalar($rank) && trim((string) $rank) !== '') {
                $reportingRankNames[strtolower(trim((string) $rank))] = trim((string) $rank);
            }
        }

        $normalised = [];

        foreach ($mappings as $sourceRank => $reportingRank) {
            if (! is_string($sourceRank) || ! is_scalar($reportingRank)) {
                throw new RuntimeException('Config Import: taxon rank mappings must contain string keys and values.');
            }

            $sourceRank = trim($sourceRank);
            $reportingRank = trim((string) $reportingRank);

            if ($sourceRank === '' || $reportingRank === '') {
                throw new RuntimeException('Config Import: taxon rank mappings cannot contain blank ranks.');
            }

            $reportingRankKey = strtolower($reportingRank);
            if (! isset($reportingRankNames[$reportingRankKey])) {
                throw new RuntimeException(
                    'Config Import: taxon rank mapping target must be a configured reporting rank: ' . $reportingRank
                );
            }

            $normalised[$sourceRank] = $reportingRankNames[$reportingRankKey];
        }

        return $normalised;
    }

}
