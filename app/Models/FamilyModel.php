<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Persistence model for families.
 */
class FamilyModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'families';

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
        'taxon_identifier',
        'scientific_name_identifier',
        'scientific_name',
        'scientific_name_authorship',
        'vernacular_name',
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
