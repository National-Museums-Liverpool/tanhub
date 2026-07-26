<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Rarity extends BaseConfig
{
    public array $labels = [
        1 => 'Extremely rare',
        2 => 'Rare',
        3 => 'Scarce',
        4 => 'Frequent',
        5 => 'Common',
    ];
}
