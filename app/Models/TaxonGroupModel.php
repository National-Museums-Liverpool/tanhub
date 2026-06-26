<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Persistence model for taxon groups.
 */
class TaxonGroupModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'taxon_groups';

    /**
     * @var string
     */
    protected $primaryKey = 'id';

    /**
     * @var string
     */
    protected $returnType = 'array';

    /**
     * @var bool
     */
    protected $useSoftDeletes = true;

    /**
     * @var array<int, string>
     */
    protected $allowedFields = [
        'title',
        'friendly',
        'external_key',
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

    /**
     * @var string
     */
    protected $deletedField = 'deleted_at';
}
