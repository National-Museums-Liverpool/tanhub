<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the deduplicated queue of dirty taxon statistic scopes.
 */
class StatsDirtyScopeModel extends Model
{
    /** @var string */
    protected $table = 'stats_dirty_scopes';

    /** @var string */
    protected $primaryKey = 'id';

    /** @var string */
    protected $returnType = 'array';

    /** @var array<int, string> */
    protected $allowedFields = [
        'scope_key',
        'stat_type',
        'projection',
        'taxon_id',
        'geographic_region_id',
        'year',
    ];

    /** @var bool */
    protected $useTimestamps = true;

    /** @var string */
    protected $createdField = 'created_at';

    /** @var string */
    protected $updatedField = 'updated_at';
}