<?php

namespace App\Services\Stats;

use App\Models\ImportOffsetModel;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Saves species-only global exclusions and atomically requests square and taxon rarity refreshes.
 */
class TaxonRarityExclusionService
{
    /** @var string Square task whose opaque checkpoint identifies each refresh request. */
    public const TASK = 'derived-stats:grid_square_stats_counts';

    /** @var string Taxon rarity task whose checkpoint identifies each refresh request. */
    public const TAXON_RARITY_TASK = 'derived-stats:taxon_rarity';

    /**
     * Validate an explicit exclusion submission without interpreting omitted fields as clearing.
     *
     * @param int $taxonId Species primary key.
     * @param array<string, mixed> $input Submitted flag and rationale.
     * @return array{exclude_from_rarity_calculations: int, exclude_from_rarity_calculations_comment: string|null}
     * @throws InvalidArgumentException If the species, flag or rationale is invalid.
     */
    public function validateInput(int $taxonId, array $input): array
    {
        $taxon = db_connect()->table('taxa')->select('id, species_id')
            ->where('id', $taxonId)->where('deleted_at', null)->get()->getRowArray();
        if ($taxon === null || (int) $taxon['species_id'] !== $taxonId) {
            throw new InvalidArgumentException('Rarity exclusion can only be set for a species.');
        }
        $flag = $input['exclude_from_rarity_calculations'] ?? null;
        $comment = $input['exclude_from_rarity_calculations_comment'] ?? '';
        if (! in_array($flag, [0, 1, '0', '1'], true) || ! is_string($comment)
            || strlen($comment) > 65535) {
            throw new InvalidArgumentException('Enter a valid exclusion flag and rationale.');
        }
        if ((int) $flag === 1 && trim($comment) === '') {
            throw new InvalidArgumentException('A rationale is required when excluding a species from rarity calculations.');
        }

        return [
            'exclude_from_rarity_calculations' => (int) $flag,
            'exclude_from_rarity_calculations_comment' => (int) $flag === 1 ? trim($comment) : null,
        ];
    }

    /**
     * Save validated fields and invalidate square scores and taxon rarity when the boolean changes.
     *
     * MySQL locks the species row to serialize concurrent staff decisions; SQLite serializes
     * writes. Both refresh revisions and the decision commit together, including in a detail save.
     *
     * @param int $taxonId Species primary key.
     * @param array<string, mixed> $input Submitted flag and rationale, validated again.
     * @return void
     * @throws InvalidArgumentException If the submission is invalid.
     * @throws RuntimeException If persistence or invalidation fails.
     */
    public function saveForTaxon(int $taxonId, array $input): void
    {
        $values = $this->validateInput($taxonId, $input);
        $db = db_connect();
        $db->transException(true)->transBegin();
        try {
            $sql = $db->table('taxa')->select('exclude_from_rarity_calculations')
                ->where('id', $taxonId)->where('species_id', $taxonId)
                ->where('deleted_at', null)->getCompiledSelect();
            if ($db->DBDriver === 'MySQLi') {
                $sql .= ' FOR UPDATE';
            }
            $current = $db->query($sql)->getRowArray();
            if ($current === null || ! $db->table('taxa')->where('id', $taxonId)
                ->update($values + ['updated_at' => date('Y-m-d H:i:s')])) {
                throw new RuntimeException('The rarity exclusion could not be saved.');
            }
            if ((int) $current['exclude_from_rarity_calculations'] !== $values['exclude_from_rarity_calculations']) {
                model(ImportOffsetModel::class)->invalidateWithCheckpoint(self::TASK, bin2hex(random_bytes(16)));
                model(ImportOffsetModel::class)->invalidateWithCheckpoint(self::TAXON_RARITY_TASK, bin2hex(random_bytes(16)));
            }
            if (! $db->transStatus() || ! $db->transCommit()) {
                throw new RuntimeException('The rarity exclusion could not be saved.');
            }
        } catch (Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /**
     * Read the effective owning-species decision for staff details.
     *
     * @param int $speciesId Owning species ID, or zero when no species is assigned.
     * @return array<string, mixed>|null Stored species decision, or null without a species.
     */
    public function getForSpecies(int $speciesId): ?array
    {
        return db_connect()->table('taxa')
            ->select('id, scientific_name, exclude_from_rarity_calculations, exclude_from_rarity_calculations_comment')
            ->where('id', $speciesId)->where('species_id', $speciesId)->get()->getRowArray();
    }
}
