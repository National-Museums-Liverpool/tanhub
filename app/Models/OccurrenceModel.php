<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Model for the `occurrences` table.
 *
 * Represents a single species occurrence record imported from an external
 * source (e.g. Indicia, NBN Atlas). Beyond the fixed columns below, the
 * table has one `<rank>_id` foreign key column per entry in
 * `Config\Import::$taxonRanks` (mirroring {@see TaxonModel}); these are
 * appended to `$allowedFields` at construction time by
 * {@see self::rankColumnsFromConfig()} rather than being listed statically,
 * since the configured rank list can vary by deployment. `blocked` /
 * `blocked_reason` implement moderation: blocked occurrences are excluded
 * from public counts and derived-stats imports (see
 * {@see \App\Services\HomeCountsService} and
 * {@see \App\Services\Import\Persistence\GeographicRegionsOccurrenceImportService}).
 */
class OccurrenceModel extends Model
{
    /**
     * Statically-known mass-assignable columns, i.e. every occurrence column
     * except the dynamic per-rank foreign keys added in the constructor.
     *
     * @var array<int, string>
     */
    private const BASE_ALLOWED_FIELDS = [
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
        'latitude',
        'longitude',
        'blocked',
        'blocked_reason',
    ];

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
     * Overwritten in the constructor to append dynamic rank columns; see
     * {@see self::BASE_ALLOWED_FIELDS} and {@see self::rankColumnsFromConfig()}.
     *
     * @var array<int, string>
     */
    protected $allowedFields = self::BASE_ALLOWED_FIELDS;

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

    /**
     * Build the model, extending `$allowedFields` with the configured taxon
     * rank columns.
     *
     * The `<rank>_id` columns on `occurrences` are created dynamically by
     * the migration from `Config\Import::$taxonRanks`, so the list of
     * mass-assignable rank columns must be computed at runtime rather than
     * hard-coded, to stay in sync with whatever ranks are configured.
     *
     * @param \CodeIgniter\Database\ConnectionInterface|null $db         Database connection to use, or null for the default.
     * @param \CodeIgniter\Validation\ValidationInterface|null $validation Validation instance to use, or null for the default.
     *
     * @return void
     */
    public function __construct(?\CodeIgniter\Database\ConnectionInterface $db = null, ?\CodeIgniter\Validation\ValidationInterface $validation = null)
    {
        parent::__construct($db, $validation);

        $this->allowedFields = array_values(array_unique(array_merge(
            self::BASE_ALLOWED_FIELDS,
            $this->rankColumnsFromConfig(),
        )));
    }

    /**
     * Derive `<rank>_id` column names from the configured taxon ranks.
     *
     * Each configured rank name is lower-cased and any run of non-alphanumeric
     * characters is collapsed to a single underscore (matching the column
     * naming used by the `occurrences` table migration), then suffixed with
     * `_id`. Non-scalar or blank rank entries are silently skipped.
     *
     * @return array<int, string> Rank foreign key column names, e.g. `['kingdom_id', 'phylum_id']`.
     */
    private function rankColumnsFromConfig(): array
    {
        $importConfig = config('Import');
        $ranks = $importConfig->taxonRanks ?? [];
        $ranks = is_array($ranks) ? $ranks : explode(',', (string) $ranks);

        $columns = [];

        foreach ($ranks as $rank) {
            if (! is_scalar($rank)) {
                continue;
            }

            $normalised = strtolower(trim((string) $rank));

            if ($normalised === '') {
                continue;
            }

            $normalised = preg_replace('/[^a-z0-9]+/i', '_', $normalised);
            $normalised = trim((string) $normalised, '_');

            if ($normalised === '') {
                continue;
            }

            $columns[] = $normalised . '_id';
        }

        return $columns;
    }
}
