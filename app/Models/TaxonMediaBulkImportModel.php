<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for bulk taxon media import headers.
 */
class TaxonMediaBulkImportModel extends Model
{
    /** @var string */
    protected $table = 'taxon_media_bulk_imports';

    /** @var string */
    protected $primaryKey = 'id';

    /** @var string */
    protected $returnType = 'array';

    /** @var array<int, string> */
    protected $allowedFields = [
        'uuid', 'owner_user_id', 'csv_filename', 'csv_path', 'status', 'total_rows', 'processed_rows',
        'next_row_id', 'error_message', 'validation_report', 'heartbeat_at', 'started_at', 'finished_at',
    ];

    /** @var bool */
    protected $useTimestamps = true;
}
