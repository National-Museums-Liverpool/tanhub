<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Read export history; checkpoint mutations belong to the locked export service.
 */
class ExportJobModel extends Model
{
    /** @var string Export checkpoint table. */
    protected $table = 'export_jobs';

    /** @var string Primary key. */
    protected $primaryKey = 'id';

    /** @var string Portable row representation. */
    protected $returnType = 'array';
}
