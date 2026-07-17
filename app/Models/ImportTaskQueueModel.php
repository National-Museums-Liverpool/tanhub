<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Persistence model for queued import tasks.
 */
class ImportTaskQueueModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'import_task_queue';

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
        'task_key',
        'status',
        'message',
        'queued_at',
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
