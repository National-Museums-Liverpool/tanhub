<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Persistence model for configured data sources.
 */
class DataSourceModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'data_sources';

    /**
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * @var string
     */
    protected $returnType = 'array';

    /**
     * @var array<int, string>
     */
    protected $allowedFields = [
        'abbr',
        'title',
        'url',
    ];

    /**
     * @var bool
     */
    protected $useTimestamps = false;
}
