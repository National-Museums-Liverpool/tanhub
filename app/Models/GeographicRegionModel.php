<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Persistence model for geographic regions.
 */
class GeographicRegionModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'geographic_regions';

    /**
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * @var string
     */
    protected $returnType = 'array';

    /**
     * @var bool
     */
    protected $useSoftDeletes = true;

    /**
     * @var array<int, string>
     */
    protected $allowedFields = [
        'higher_geography_identifier',
        'higher_geography',
        'location_type',
        'footprint_geometry',
        'data_source_id',
    ];

    /**
     * @var bool
     */
    protected $useTimestamps = true;

    /**
     * @var string
     */
    protected $createdField = 'created_at';

    /**
     * @var string
     */
    protected $updatedField = 'updated_at';

    /**
     * @var string
     */
    protected $deletedField = 'deleted_at';
}