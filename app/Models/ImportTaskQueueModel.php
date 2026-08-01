<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `import_task_queue` table.
 *
 * Backs the FIFO queue drained by {@see \App\Controllers\Imports} when an
 * import task is enqueued: rows are processed in `queued_at` order until a
 * task is blocked, fails, or throws. Each row's lifecycle moves through the
 * statuses in {@see \App\Controllers\Imports::ACTIVE_QUEUE_STATUSES} plus a
 * terminal state, with `run_id` linking to the {@see ImportRunModel} row
 * created for that execution once it starts.
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
     * Mass-assignable columns.
     *
     * - `source_key`   Import task key that was enqueued (see {@see ImportOffsetModel}).
     * - `run_id`       {@see ImportRunModel} ID once the task has started running, else null.
     * - `status`       Queue row status (e.g. queued/running/completed/failed).
     * - `queued_at`    Timestamp the task was added to the queue; defines FIFO order.
     * - `started_at`   Timestamp processing began.
     * - `finished_at`  Timestamp processing ended.
     *
     * @var array<int, string>
     */
    protected $allowedFields = [
        'source_key',
        'run_id',
        'status',
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
