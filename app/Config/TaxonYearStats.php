<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use InvalidArgumentException;

/**
 * Taxon year statistics rebuild configuration.
 */
class TaxonYearStats extends BaseConfig
{
    /** @var int Number of completed years to retain; zero means all available history. */
    public int $historyYears = 10;

    /** @var int Number of years processed per resumable task invocation. */
    public int $yearsPerRun = 5;

    /**
     * Load and validate yearly-statistics settings.
     *
     * @throws InvalidArgumentException If a setting is invalid.
     */
    public function __construct()
    {
        parent::__construct();
        $this->historyYears = $this->integerFromEnvironment('taxonYearStats.historyYears', $this->historyYears, 0, 10000);
        $this->yearsPerRun = $this->integerFromEnvironment('taxonYearStats.yearsPerRun', $this->yearsPerRun, 1, 1000);
    }

    /**
     * Read one bounded integer from the environment.
     *
     * @param string $key Environment key.
     * @param int $default Default value.
     * @param int $minimum Minimum accepted value.
     * @param int $maximum Maximum accepted value.
     *
     * @return int Validated value.
     *
     * @throws InvalidArgumentException If the value is invalid.
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
