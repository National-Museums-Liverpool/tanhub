<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `taxon_names` table.
 *
 * Represents an alternate/vernacular or scientific name for a
 * {@see TaxonModel}, distinct from the taxon's primary `scientific_name`.
 *
 * NOTE: unlike the other models in this namespace, no `$allowedFields` are
 * declared here, so the base CodeIgniter Model's field protection will
 * strip all fields from any `insert()`/`update()` array call, effectively
 * preventing mass assignment via this model as written. This looked like it
 * may be an oversight rather than intentional, but no logic was changed as
 * part of this documentation pass — flagged here for human review.
 */
class TaxonNameModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'taxon_names';

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
