<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `recording_schemes` table.
 *
 * Recording schemes are imported lookup records (organisations/schemes that
 * a taxon may be recorded under); see {@see \App\Models\TaxonModel::$allowedFields}
 * for the `recording_scheme_id` foreign key that references this table.
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
     * Mass-assignable columns.
     *
     * - `external_key` Identifier used to match this scheme during import.
     * - `title`        Human-readable scheme name.
     * - `description`  Free-text description of the scheme.
     *
     * @var array<int, string>
     */
    protected $allowedFields = [
        'external_key',
        'title',
        'description',
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
