<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Import pipeline configuration.
 */
class Import extends BaseConfig
{
    /**
     * @var int
     */
    public int $defaultLimit = 1000;

    /**
     * @var int
     */
    public int $defaultPageSize = 200;

    /**
     * @var int
     */
    public int $httpTimeout = 30;

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
            'endpoint' => 'occurrences/search',
            'records_key' => 'occurrences',
            'checkpoint_param' => 'since',
            'checkpoint_field' => 'lastModified',
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
     * Taxonomic groups we allow reporting at.
     *
     * @var array|string
     */
    public array|string $taxonGroups = [
        'insect - moth',
        'insect - caddis fly (Trichoptera)',
    ];

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
    }

}
