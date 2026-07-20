<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Persistence model for taxa.
 */
class TaxonModel extends Model
{
    /**
     * @var array<int, string>
     */
    private const BASE_ALLOWED_FIELDS = [
        'rarity_group_name',
        'taxon_remarks',
        'blocked',
        'blocked_reason',
    ];

    /**
     * @var string
     */
    protected $table = 'taxa';

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

    public function __construct(?\CodeIgniter\Database\ConnectionInterface $db = null, ?\CodeIgniter\Validation\ValidationInterface $validation = null)
    {
        parent::__construct($db, $validation);

        $this->allowedFields = array_values(array_unique(array_merge(
            self::BASE_ALLOWED_FIELDS,
            $this->rankColumnsFromConfig(),
        )));
    }

    /**
     * @return array<int, string>
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
