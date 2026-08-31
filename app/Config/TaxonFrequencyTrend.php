<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use InvalidArgumentException;

/**
 * Frequency trend scoring configuration.
 */
class TaxonFrequencyTrend extends BaseConfig
{
    /**
     * Weighting given to the number of occupied 2km grid squares when calculating frequency trends.
     *
     * @var float
     */
    public float $gridSquareWeight = 1.0;

    /**
     * Weighting given to occurrence records when calculating frequency trends.
     *
     * @var float
     */
    public float $occurrenceWeight = 1.0;

    /**
     * Load and validate frequency trend weight overrides.
     *
     * @throws InvalidArgumentException If a weight is invalid or both weights are zero.
     */
    public function __construct()
    {
        parent::__construct();

        $this->gridSquareWeight = $this->weightFromEnvironment('taxonFrequencyTrend.gridSquareWeight', $this->gridSquareWeight);
        $this->occurrenceWeight = $this->weightFromEnvironment('taxonFrequencyTrend.occurrenceWeight', $this->occurrenceWeight);

        if ($this->gridSquareWeight === 0.0 && $this->occurrenceWeight === 0.0) {
            throw new InvalidArgumentException('At least one taxon trend weight must be greater than zero.');
        }
    }

    /**
     * Read and validate one weight from the environment.
     *
     * @param string $key     Environment key.
     * @param float  $default Default weight.
     *
     * @return float Validated weight.
     *
     * @throws InvalidArgumentException If the configured value is invalid.
     */
    private function weightFromEnvironment(string $key, float $default): float
    {
        $configuredWeight = env($key);

        if ($configuredWeight === null || $configuredWeight === '') {
            return $default;
        }

        if (! is_numeric($configuredWeight) || (float) $configuredWeight < 0) {
            throw new InvalidArgumentException($key . ' must be a non-negative number.');
        }

        return (float) $configuredWeight;
    }
}
