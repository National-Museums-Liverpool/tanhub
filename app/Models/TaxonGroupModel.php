<?php

namespace App\Models;

use CodeIgniter\Model;

class TaxonGroupModel extends Model
{
    protected $table = 'taxon_groups';

    protected $primaryKey = 'id';

    protected $returnType = 'array';

    protected $useSoftDeletes = true;

    protected $allowedFields = [
        'title',
        'friendly',
        'external_key',
    ];

    protected $useTimestamps = true;

    protected $createdField = 'created_at';

    protected $updatedField = 'updated_at';

    protected $deletedField = 'deleted_at';
}
