<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `import_runs` table.
 *
 * Records a single execution (attempt) of an import task, capturing its
 * status and result counters. Rows are created when a task starts and
 * updated as it progresses/finishes; see {@see ImportTaskQueueModel} for the
 * FIFO queue that schedules these runs and {@see \App\Controllers\Imports}
 * for how run status is surfaced to admins.
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
     * Mass-assignable columns.
     *
     * - `source_key`     Import task key this run executed (see {@see ImportOffsetModel}).
     * - `source_abbr`    Short label for the originating data source.
     * - `status`         Current run status (e.g. running/completed/failed).
     * - `checkpoint`     Checkpoint token reached by this run, if any.
     * - `fetched_count`  Number of records fetched from the source.
     * - `inserted_count` Number of new records inserted.
     * - `updated_count`  Number of existing records updated.
     * - `skipped_count`  Number of records skipped (e.g. unchanged/invalid).
     * - `error_count`    Number of records that failed to process.
     * - `message`        Free-text status/error message for display.
     * - `started_at`     Timestamp the run began.
     * - `finished_at`    Timestamp the run ended, or null while still running.
     *
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
