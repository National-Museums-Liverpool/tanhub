<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `taxon_groups` table.
 *
 * Taxon groups are the top-level taxonomic groupings (e.g. birds, plants)
 * that a {@see TaxonModel} belongs to via `taxon_group_id`. Groups are
 * imported from Indicia and matched/upserted via `indicia_taxon_group_id`.
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
     * Mass-assignable columns.
     *
     * - `title`                  Full display name of the group.
     * - `friendly`               Optional shorter/user-friendly display name.
     * - `external_key`           External identifier used to match this group during import.
     * - `indicia_taxon_group_id` Unique Indicia source ID this group was imported from.
     * - `implied`                1 when the group was auto-created to satisfy a taxon's
     *                            reference rather than imported directly from Indicia.
     *
     * @var array<int, string>
     */
    protected $allowedFields = [
        'title',
        'friendly',
        'external_key',
        'indicia_taxon_group_id',
        'implied',
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
