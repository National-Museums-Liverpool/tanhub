<?php

namespace App\Services\Import\Adapter;

use Config\Import as ImportConfig;
use InvalidArgumentException;

/**
 * Creates import source adapters from import configuration.
 */
class ImportSourceAdapterFactory
{
    public function __construct(
        private readonly ImportConfig $config,
    ) {
    }

    /**
     * Create an adapter for the requested source key.
     */
    public function make(string $sourceKey): ImportSourceAdapterInterface
    {
        $source = strtolower($sourceKey);
        $sourceConfig = $this->config->taxonomySources[$source] ?? null;

        if (! is_array($sourceConfig)) {
            throw new InvalidArgumentException('Unknown import source: ' . $sourceKey);
        }

        $client = service('curlrequest');

        return match ($source) {
            'indicia' => new IndiciaImportAdapter($client, $this->config, $sourceConfig),
            default => throw new InvalidArgumentException('Unsupported import source: ' . $sourceKey),
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
            throw new InvalidArgumentException('Import source abbreviation is not configured for: ' . $sourceKey);
        }

        return (string) $sourceConfig['abbr'];
    }
}