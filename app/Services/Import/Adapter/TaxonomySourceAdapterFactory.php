<?php

namespace App\Services\Import\Adapter;

use Config\Import as ImportConfig;
use InvalidArgumentException;

/**
 * Creates taxonomy source adapters from import configuration.
 */
class TaxonomySourceAdapterFactory
{
    public function __construct(
        private readonly ImportConfig $config,
    ) {
    }

    /**
     * Create a taxonomy adapter for the requested source key.
     */
    public function make(string $sourceKey): TaxonomySourceAdapterInterface
    {
        $source = strtolower($sourceKey);
        $sourceConfig = $this->config->taxonomySources[$source] ?? null;

        if (! is_array($sourceConfig)) {
            throw new InvalidArgumentException('Unknown taxonomy source: ' . $sourceKey);
        }

        $client = service('curlrequest');

        return match ($source) {
            'indicia' => new IndiciaTaxonomyAdapter($client, $this->config, $sourceConfig),
            default => throw new InvalidArgumentException('Unsupported taxonomy source: ' . $sourceKey),
        };
    }

    /**
     * Resolve configured source abbreviation for a source key.
     */
    public function sourceAbbr(string $sourceKey): string
    {
        $source = strtolower($sourceKey);
        $sourceConfig = $this->config->taxonomySources[$source] ?? null;

        if (! is_array($sourceConfig) || ! isset($sourceConfig['abbr'])) {
            throw new InvalidArgumentException('Taxonomy source abbreviation is not configured for: ' . $sourceKey);
        }

        return (string) $sourceConfig['abbr'];
    }
}
