<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Persistence model for occurrences.
 */
class OccurrenceModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'occurrences';

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
        'unique_key',
        'taxon_id',
        'taxon_name_id',
        'from_date',
        'to_date',
        'grid_ref',
        'grid_ref_2km',
        'locality',
        'recorded_by',
        'identified_by',
        'identification_verification_status',
        'sex',
        'life_stage',
        'organism_quantity',
        'data_source_id',
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
