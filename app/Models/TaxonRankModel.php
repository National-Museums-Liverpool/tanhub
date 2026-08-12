<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `taxon_ranks` table.
 *
 * Taxon ranks are the ordered taxonomic levels (e.g. kingdom, phylum, ...,
 * species) configured via `Config\Import::$taxonRanks`. {@see TaxonModel}
 * and {@see OccurrenceModel} each dynamically add one `<rank>_id` foreign
 * key column per configured rank, so the row set here effectively drives
 * part of the schema of those tables.
 */
class TaxonRankModel extends Model
{
    /**
     * @var string
     */
    protected $table = 'taxon_ranks';

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
     * - `rank`       Rank name, matched against `Config\Import::$taxonRanks` entries.
     * - `abbr`       Short display abbreviation for the rank.
     * - `sort_order` Position of this rank in the taxonomic hierarchy (lower sorts first).
     * - `is_reporting` Whether this rank is configured as a reporting rank.
     *
     * @var array<int, string>
     */
    protected $allowedFields = [
        'rank',
        'abbr',
        'sort_order',
        'is_reporting',
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
