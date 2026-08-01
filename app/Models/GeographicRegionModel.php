<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `geographic_regions` table.
 *
 * Geographic regions are imported administrative/named areas (e.g. from
 * `grid_squares.xml`) used to tag occurrences via
 * {@see GeographicRegionsOccurrenceModel}. `higher_geography_identifier` is
 * the external key used to resolve a region during import (e.g. by grid
 * square stats imports); see repo docs for the import pipeline.
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
     * Mass-assignable columns.
     *
     * - `higher_geography_identifier` External key used to match/resolve this region during import.
     * - `higher_geography`            Human-readable name of the region.
     * - `location_type`               Type/level of the region (e.g. county, vice-county).
     * - `footprint_geometry`          Serialised boundary geometry for the region.
     * - `data_source_id`              Originating {@see DataSourceModel} record.
     *
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