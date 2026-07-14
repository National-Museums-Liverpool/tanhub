<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Persistence model for taxa.
 */
class TaxonModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'taxa';

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
        'blocked',
        'blocked_reason',
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
