<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `import_task_queue` table.
 *
 * Backs admin requests shared by the Imports controller and import:auto.
 * Requests are selected in ID order, continue across successful batches,
 * and are removed once complete. Failed requests remain for explicit retry
 * or removal. The run_id links to the latest execution or prerequisite run.
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
     * - `status`       Request status (queued/running/failed; legacy completed rows are hidden).
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
