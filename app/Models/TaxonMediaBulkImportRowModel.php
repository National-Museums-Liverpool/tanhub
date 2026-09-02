<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for rows staged by a bulk taxon media import.
 */
class TaxonMediaBulkImportRowModel extends Model
{
    /** @var string */
    protected $table = 'taxon_media_bulk_import_rows';

    /** @var string */
    protected $primaryKey = 'id';

    /** @var string */
    protected $returnType = 'array';

    /** @var array<int, string> */
    protected $allowedFields = [
        'import_id', 'row_number', 'taxon_id', 'photo_filename', 'staged_path',
        'mime_type', 'staged_bytes', 'checksum',
        'alt_text', 'caption', 'attribution', 'license', 'sort_order', 'is_primary',
        'status', 'media_id', 'error_message',
    ];

    /** @var bool */
    protected $useTimestamps = true;
}
