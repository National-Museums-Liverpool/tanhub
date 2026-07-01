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

        return match ($source) {
            'nbn' => new NbnAtlasOccurrencesAdapter($client, $sourceConfig, $this->config->httpTimeout),
            'indicia' => new IndiciaOccurrencesAdapter($client, $sourceConfig, $this->config->httpTimeout),
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
