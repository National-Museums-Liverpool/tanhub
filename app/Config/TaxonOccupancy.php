<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use InvalidArgumentException;

/**
 * Configures the occurrence history used by species occupancy measurements.
 */
class TaxonOccupancy extends BaseConfig
{
    /**
     * Number of calendar years to consider, including the current year through today.
     *
     * @var int
     */
    public int $historyYears = 50;

    /**
     * Load the history override without silently truncating invalid environment values.
     *
     * @throws InvalidArgumentException If the history window is not a positive integer.
     */
    public function __construct()
    {
        $defaultHistoryYears = $this->historyYears;
        parent::__construct();

        // BaseConfig casts environment values before our stricter integer validation.
        $this->historyYears = $defaultHistoryYears;
        $configuredHistoryYears = env('taxonOccupancy.historyYears');

        if ($configuredHistoryYears !== null && $configuredHistoryYears !== '') {
            $historyYears = filter_var($configuredHistoryYears, FILTER_VALIDATE_INT);

            if ($historyYears === false || $historyYears < 1) {
                throw new InvalidArgumentException('taxonOccupancy.historyYears must be a positive integer.');
            }

            $this->historyYears = $historyYears;
        }
    }
}
