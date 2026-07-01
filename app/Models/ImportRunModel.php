<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Persistence model for import run tracking.
 */
class ImportRunModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'import_runs';

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
        'source_key',
        'source_abbr',
        'status',
        'checkpoint',
        'fetched_count',
        'inserted_count',
        'updated_count',
        'skipped_count',
        'error_count',
        'message',
        'started_at',
        'finished_at',
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
}
