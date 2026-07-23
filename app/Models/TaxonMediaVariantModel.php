<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Persistence model for taxon media variants.
 */
class TaxonMediaVariantModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'taxon_media_variants';

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
    protected $useTimestamps = false;

    /**
     * @var array<int, string>
     */
    protected $allowedFields = [
        'taxon_media_id',
        'variant_key',
        'storage_path',
        'mime_type',
        'bytes',
        'width',
        'height',
        'created_at',
    ];
}
