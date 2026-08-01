<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `geographic_regions_occurrences` many-to-many join table.
 *
 * Links {@see GeographicRegionModel} rows to {@see OccurrenceModel} rows so
 * an occurrence can be tagged with the geographic regions its coordinates
 * fall within. The underlying table's actual uniqueness constraint is the
 * composite key (`geographic_region_id`, `occurrence_id`); CodeIgniter's
 * Model only supports a single-column `$primaryKey`, so this is declared as
 * `geographic_region_id` with auto-increment disabled purely to satisfy the
 * base Model — callers should not rely on `find()`/`delete()` by primary key
 * alone returning/removing a single row, and should filter on both columns
 * explicitly instead.
 */
class GeographicRegionsOccurrenceModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'geographic_regions_occurrences';

    /**
     * @var string Not a true single-column identifier; see class docblock.
     */
    protected $primaryKey = 'geographic_region_id';

    /**
     * @var bool No surrogate ID column exists on this join table.
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
     * @var bool Pure link rows; no independent lifecycle to timestamp.
     */
    protected $useTimestamps = false;
}