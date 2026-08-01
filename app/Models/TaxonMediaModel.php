<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `taxon_media` table.
 *
 * Represents an uploaded media item (typically a photo) attached to a
 * {@see TaxonModel}. Each media row can have multiple derived image sizes
 * stored in {@see TaxonMediaVariantModel}. Read access is via
 * {@see \App\Services\TaxonMediaReadService}, which orders results by
 * `is_primary` then `sort_order` to pick the featured image for a taxon;
 * writes go through {@see \App\Services\TaxonMediaUploadService}.
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
     * Mass-assignable columns.
     *
     * - `uuid`               Stable public identifier for the media item.
     * - `taxon_id`           Owning {@see TaxonModel} record.
     * - `original_filename`  Filename as uploaded, kept for display/attribution.
     * - `storage_path`       Path to the original stored file.
     * - `mime_type`          MIME type of the original file.
     * - `bytes`              File size in bytes.
     * - `width`               Original image width in pixels.
     * - `height`              Original image height in pixels.
     * - `alt_text`           Accessibility/alt text for the image.
     * - `caption`            Display caption.
     * - `attribution`        Photographer/source attribution text.
     * - `license`            License the media is made available under.
     * - `sort_order`         Manual ordering among a taxon's media (ascending).
     * - `is_primary`         1 marks the featured image shown first for the taxon.
     *
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
