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
     * @var int
     */
    public int $httpTimeout = 30;

    /**
     * Maximum age in seconds for a UI import task to remain running before recovery.
     *
     * @var int
     */
    public int $uiTaskStaleAfter = 3600;

    public string $indiciaWarehouseUrl = '';

    public string $indiciaProjId = '';

    public int $indiciaTaxonListId = 0;

    public string $indiciaUsername = '';

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
     * Taxonomic groups we allow reporting at.
     *
     * @var array|string
     */
    public array|string $geographicRegions = [
        'Cheshire',
        'South Lancashire',
        'West Lancashire',
    ];

    /**
     * Geographic region location type.
     *
     * @var string
     */
    public string $geographicRegionLocationType = 'Vice County';

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
     * Constructor loads array overrides from .env.
     */
    public function __construct()
    {
        parent::__construct();
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
        if (is_string($configuredGeographicRegions) && $configuredGeographicRegions !== '') {
            $this->geographicRegions = array_map('trim', str_getcsv($configuredGeographicRegions));
            log_message('info', 'Configured geographic regions overriden: ' . $configuredGeographicRegions);
        }
        $configuredGeographicRegionLocationType = env('import.geographicRegionLocationType');
        if (is_string($configuredGeographicRegionLocationType) && $configuredGeographicRegionLocationType !== '') {
            $this->geographicRegionLocationType = trim($configuredGeographicRegionLocationType);
            log_message('info', 'Configured geographic region location type overriden: ' . $configuredGeographicRegionLocationType);
        }
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
