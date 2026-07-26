<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Rarity scoring configuration.
 */
class Rarity extends BaseConfig
{
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
    public float $squareWeight = 1.0;

    /**
     * Weighting given to the number of occurrences for a taxon when calculating rarity scores.
     *
     * @var float
     */
    public float $occurrenceWeight = 1.0;

    /**
     * Load rarity scoring overrides from environment variables.
     */
    public function __construct()
    {
        parent::__construct();

        $configuredSquareWeight = env('rarity.squareWeight');

        if ($configuredSquareWeight !== null && $configuredSquareWeight !== '') {
            $this->squareWeight = (float) $configuredSquareWeight;
        }

        $configuredOccurrenceWeight = env('rarity.occurrenceWeight');

        if ($configuredOccurrenceWeight !== null && $configuredOccurrenceWeight !== '') {
            $this->occurrenceWeight = (float) $configuredOccurrenceWeight;
        }
    }
}
