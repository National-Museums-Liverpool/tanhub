<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;
use InvalidArgumentException;

/**
 * Rarity scoring configuration.
 */
class Rarity extends BaseConfig
{
    /**
     * Number of calendar years to consider, including the current year through today.
     *
     * @var int
     */
    public int $historyYears = 50;

    /**
     * @var array<int, string>
     */
    public array $labels = [
        1 => 'Extremely rare',
        2 => 'Rare',
        3 => 'Scarce',
        4 => 'Frequent',
        5 => 'Common',
    ];

    /**
     * Weighting given to the number of 2km grid squares occupied by a taxon when calculating
     * rarity scores.
     *
     * @var float
     */
    public float $gridSquareWeight = 1.0;

    /**
     * Weighting given to the number of occurrences for a taxon when calculating rarity scores.
     *
     * @var float
     */
    public float $occurrenceWeight = 1.0;

    /**
     * Load rarity scoring overrides from environment variables.
     *
     * @throws InvalidArgumentException If the history window is not a positive integer.
     */
    public function __construct()
    {
        $defaultHistoryYears = $this->historyYears;
        parent::__construct();

        // BaseConfig casts environment values before our stricter integer validation.
        $this->historyYears = $defaultHistoryYears;
        $configuredHistoryYears = env('rarity.historyYears');

        if ($configuredHistoryYears !== null && $configuredHistoryYears !== '') {
            $historyYears = filter_var($configuredHistoryYears, FILTER_VALIDATE_INT);

            if ($historyYears === false || $historyYears < 1) {
                throw new InvalidArgumentException('rarity.historyYears must be a positive integer.');
            }

            $this->historyYears = $historyYears;
        }

        $configuredSquareWeight = env('rarity.gridSquareWeight');

        if ($configuredSquareWeight !== null && $configuredSquareWeight !== '') {
            $this->gridSquareWeight = (float) $configuredSquareWeight;
        }

        $configuredOccurrenceWeight = env('rarity.occurrenceWeight');

        if ($configuredOccurrenceWeight !== null && $configuredOccurrenceWeight !== '') {
            $this->occurrenceWeight = (float) $configuredOccurrenceWeight;
        }
    }
}
