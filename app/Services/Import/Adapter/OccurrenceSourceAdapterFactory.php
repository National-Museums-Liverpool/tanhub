<?php

namespace App\Services\Import\Adapter;

use Config\Import as ImportConfig;
use InvalidArgumentException;

/**
 * Creates occurrence source adapters from import configuration.
 */
class OccurrenceSourceAdapterFactory
{
    /**
     * Create a factory for installation-scoped occurrence adapters.
     *
     * @param ImportConfig $config Validated import configuration.
     */
    public function __construct(
        private readonly ImportConfig $config,
    ) {
    }

    /**
     * Create an adapter for the requested source key.
     *
     * @param string      $sourceKey Occurrence source.
     * @param string|null $geographyGroup Selected NBN stream; Indicia uses all groups.
     *
     * @return OccurrenceSourceAdapterInterface Configured source adapter.
     */
    public function make(string $sourceKey, ?string $geographyGroup = null): OccurrenceSourceAdapterInterface
    {
        $source = strtolower($sourceKey);
        $sourceConfig = $this->config->occurrenceSources[$source] ?? null;

        if (! is_array($sourceConfig)) {
            throw new InvalidArgumentException('Unknown occurrence source: ' . $sourceKey);
        }

        $client = service('curlrequest');
        $resolvedConfig = $sourceConfig;

        if ($source === 'nbn') {
            $groups = $this->config->geographicRegions;
            $key = $geographyGroup ?? (count($groups) === 1 ? array_key_first($groups) : null);
            if ($key === null || ! isset($groups[$key])) {
                throw new InvalidArgumentException('Select a configured geography group for NBN occurrences.');
            }
            $group = $groups[$key];
            $resolvedConfig = array_merge($sourceConfig, [
                'taxon_ranks' => $this->config->taxonRanks,
                'geographic_regions' => array_map(
                    static fn (array $location): string => $location['nbnName'] ?? $location['irecordName'],
                    $group['locations'],
                ),
                'geographic_region_field' => $group['nbnField'],
                'min_taxon_rank_id' => $this->config->nbnMinTaxonRankId,
                'nbn_filter_query' => $this->config->nbnApiFilterQuery,
            ]);
        }

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
                'min_taxon_rank_sort_order' => $this->config->indiciaMinTaxonRankSortOrder,
                'geographic_regions' => $this->config->geographicRegions,
                'maximum_coordinate_uncertainty_in_meters' => $this->config->maximumCoordinateUncertaintyInMeters,
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
