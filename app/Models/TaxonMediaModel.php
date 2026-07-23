<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Persistence model for taxon media.
 */
class TaxonMediaModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'taxon_media';

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
        'uuid',
        'taxon_id',
        'original_filename',
        'storage_path',
        'mime_type',
        'bytes',
        'width',
        'height',
        'alt_text',
        'caption',
        'attribution',
        'license',
        'sort_order',
        'is_primary',
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
