<?php

namespace App\Services\Import\Adapter;

use Config\Import as ImportConfig;
use InvalidArgumentException;

/**
 * Creates occurrence source adapters from import configuration.
 */
class OccurrenceSourceAdapterFactory
{
    public function __construct(
        private readonly ImportConfig $config,
    ) {
    }

    /**
     * Create an adapter for the requested source key.
     */
    public function make(string $sourceKey): OccurrenceSourceAdapterInterface
    {
        $source = strtolower($sourceKey);
        $sourceConfig = $this->config->occurrenceSources[$source] ?? null;

        if (! is_array($sourceConfig)) {
            throw new InvalidArgumentException('Unknown occurrence source: ' . $sourceKey);
        }

        $client = service('curlrequest');
        $resolvedConfig = $sourceConfig;

        if ($source === 'indicia') {
            $resolvedConfig = array_merge($sourceConfig, [
                'warehouse_url' => $this->config->indiciaWarehouseUrl,
                'es_endpoint' => $this->config->indiciaOccurrencesEsEndpoint,
                'project_id' => $this->config->indiciaProjId,
                'taxon_list_id' => $this->config->indiciaTaxonListId,
                'username' => $this->config->indiciaUsername,
                'secret' => $this->config->indiciaSecret,
                'taxon_groups' => $this->config->taxonGroups,
                'taxon_ranks' => $this->config->taxonRanks,
                'geographic_regions' => $this->config->geographicRegions,
                'geographic_region_location_type' => $this->config->geographicRegionLocationType,
            ]);
        }

        return match ($source) {
            'nbn' => new NbnAtlasOccurrencesAdapter($client, $resolvedConfig, $this->config->httpTimeout),
            'indicia' => new IndiciaOccurrencesAdapter($client, $resolvedConfig, $this->config->httpTimeout),
            default => throw new InvalidArgumentException('Unsupported occurrence source: ' . $sourceKey),
        };
    }

    /**
     * Resolve configured source abbreviation for a source key.
     */
    public function sourceAbbr(string $sourceKey): string
    {
        $source = strtolower($sourceKey);
        $sourceConfig = $this->config->occurrenceSources[$source] ?? null;

        if (! is_array($sourceConfig) || ! isset($sourceConfig['abbr'])) {
            throw new InvalidArgumentException('Source abbreviation is not configured for: ' . $sourceKey);
        }

        return (string) $sourceConfig['abbr'];
    }
}
