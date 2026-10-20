<?php

namespace App\Services\Import\Adapter;

use Config\Import as ImportConfig;
use InvalidArgumentException;

/**
 * Creates import source adapters from import configuration.
 */
class ImportSourceAdapterFactory
{
    /**
     * Create a factory for installation-scoped report adapters.
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
     * @param string      $sourceKey Source name.
     * @param string|null $geographyGroup Active geography group for lookup reports.
     *
     * @return ImportSourceAdapterInterface Source adapter.
     */
    public function make(string $sourceKey, ?string $geographyGroup = null): ImportSourceAdapterInterface
    {
        $source = strtolower($sourceKey);
        $sourceConfig = $this->config->taxonomySources[$source] ?? null;

        if (! is_array($sourceConfig)) {
            throw new InvalidArgumentException('Unknown import source: ' . $sourceKey);
        }

        $client = service('curlrequest');

        return match ($source) {
            'indicia' => new IndiciaImportAdapter($client, $this->config, $sourceConfig, $geographyGroup),
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