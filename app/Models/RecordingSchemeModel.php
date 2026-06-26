<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Persistence model for recording schemes.
 */
class RecordingSchemeModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'recording_schemes';

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
        'external_key',
        'title',
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
