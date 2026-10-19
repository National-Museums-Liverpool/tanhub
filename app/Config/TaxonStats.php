<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use InvalidArgumentException;

/**
 * Runtime limits for taxon statistics rebuilds.
 */
class TaxonStats extends BaseConfig
{
    /** @var int Maximum distinct taxa aggregated in one rebuild query. */
    public int $taxaPerBatch = 100;

    /** @var int Maximum distinct taxa processed in one rebuild invocation. */
    public int $maxTaxaPerRun = 500;

    /** @var int Maximum runtime in seconds for one rebuild invocation. */
    public int $maxRuntimeSeconds = 20;

    /**
     * Load and validate taxon-statistics runtime limits.
     *
     * @throws InvalidArgumentException When a configured limit is invalid.
     */
    public function __construct()
    {
        parent::__construct();
        $this->taxaPerBatch = $this->integerFromEnvironment(
            'taxonStats.taxaPerBatch',
            $this->taxaPerBatch,
            1,
            10000,
        );
        $this->maxTaxaPerRun = $this->integerFromEnvironment(
            'taxonStats.maxTaxaPerRun',
            $this->maxTaxaPerRun,
            1,
            100000,
        );
        $this->maxRuntimeSeconds = $this->integerFromEnvironment(
            'taxonStats.maxRuntimeSeconds',
            $this->maxRuntimeSeconds,
            1,
            86400,
        );
    }

    /**
     * Read one bounded integer from the environment.
     *
     * @param string $key Environment key.
     * @param int    $default Default value.
     * @param int    $minimum Minimum accepted value.
     * @param int    $maximum Maximum accepted value.
     *
     * @return int Validated value.
     *
     * @throws InvalidArgumentException When the value is invalid.
     */
    private function integerFromEnvironment(string $key, int $default, int $minimum, int $maximum): int
    {
        $configuredValue = env($key);
        if ($configuredValue === null || $configuredValue === '') {
            return $default;
        }
        $value = filter_var($configuredValue, FILTER_VALIDATE_INT);
        if ($value === false || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("{$key} must be an integer from {$minimum} to {$maximum}.");
        }
        return $value;
    }
}
