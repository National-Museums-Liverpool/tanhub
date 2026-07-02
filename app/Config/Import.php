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
            'endpoint' => '',
            'checkpoint_param' => 'since',
            'checkpoint_field' => 'lastModified',
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
    }

}
