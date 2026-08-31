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
     * Minimum number of years containing data required to classify a directional trend.
     *
     * @var int
     */
    public int $minimumOccupiedYears = 3;

    /**
     * Minimum weighted coefficient of determination required for a directional trend.
     *
     * @var float
     */
    public float $minimumRSquared = 0.5;

    /**
     * Absolute weighted slope at or below which a species is treated as stable.
     *
     * @var float
     */
    public float $slopeTolerance = 0.000001;

    /**
     * Half-life in years for recency weighting; zero disables recency weighting.
     *
     * @var float
     */
    public float $recentYearHalfLife = 3.0;

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
        $this->minimumOccupiedYears = $this->integerFromEnvironment(
            'taxonFrequencyTrend.minimumOccupiedYears',
            $this->minimumOccupiedYears,
            1,
            9,
        );
        $this->minimumRSquared = $this->boundedFloatFromEnvironment(
            'taxonFrequencyTrend.minimumRSquared',
            $this->minimumRSquared,
            0.0,
            1.0,
        );
        $this->slopeTolerance = $this->boundedFloatFromEnvironment(
            'taxonFrequencyTrend.slopeTolerance',
            $this->slopeTolerance,
            0.0,
            PHP_FLOAT_MAX,
        );
        $this->recentYearHalfLife = $this->boundedFloatFromEnvironment(
            'taxonFrequencyTrend.recentYearHalfLife',
            $this->recentYearHalfLife,
            0.0,
            PHP_FLOAT_MAX,
        );

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

    /**
     * Read and validate one bounded integer from the environment.
     *
     * @param string $key     Environment key.
     * @param int    $default Default value.
     * @param int    $minimum Minimum accepted value.
     * @param int    $maximum Maximum accepted value.
     *
     * @return int Validated integer.
     *
     * @throws InvalidArgumentException If the configured value is invalid.
     */
    private function integerFromEnvironment(string $key, int $default, int $minimum, int $maximum): int
    {
        $configuredValue = env($key);

        if ($configuredValue === null || $configuredValue === '') {
            return $default;
        }

        $validatedValue = filter_var($configuredValue, FILTER_VALIDATE_INT);

        if ($validatedValue === false || $validatedValue < $minimum || $validatedValue > $maximum) {
            throw new InvalidArgumentException("{$key} must be an integer from {$minimum} to {$maximum}.");
        }

        return $validatedValue;
    }

    /**
     * Read and validate one bounded float from the environment.
     *
     * @param string $key     Environment key.
     * @param float  $default Default value.
     * @param float  $minimum Minimum accepted value.
     * @param float  $maximum Maximum accepted value.
     *
     * @return float Validated float.
     *
     * @throws InvalidArgumentException If the configured value is invalid.
     */
    private function boundedFloatFromEnvironment(
        string $key,
        float $default,
        float $minimum,
        float $maximum,
    ): float {
        $configuredValue = env($key);

        if ($configuredValue === null || $configuredValue === '') {
            return $default;
        }

        if (! is_numeric($configuredValue)) {
            throw new InvalidArgumentException("{$key} must be a number from {$minimum} to {$maximum}.");
        }

        $value = (float) $configuredValue;

        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("{$key} must be a number from {$minimum} to {$maximum}.");
        }

        return $value;
    }
}
