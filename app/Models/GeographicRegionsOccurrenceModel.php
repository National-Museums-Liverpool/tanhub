<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Persistence model for geographic region to occurrence links.
 */
class GeographicRegionsOccurrenceModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'geographic_regions_occurrences';

    /**
     * @var string
     */
    protected $primaryKey = 'geographic_region_id';

    /**
     * @var bool
     */
    protected $useAutoIncrement = false;

    /**
     * @var string
     */
    protected $returnType = 'array';

    /**
     * @var array<int, string>
     */
    protected $allowedFields = [
        'geographic_region_id',
        'occurrence_id',
    ];

    /**
     * @var bool
     */
    protected $useTimestamps = false;
}