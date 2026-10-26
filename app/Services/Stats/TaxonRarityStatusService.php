<?php

namespace App\Services\Stats;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Validates and saves independent expert common/rare decisions for species scopes.
 */
class TaxonRarityStatusService
{
    /**
     * Read stored measurements and curated decisions, keyed by region ID or `global`.
     *
     * @param int $taxonId Taxon primary key.
     * @return array<string|int, array<string, mixed>> Stored statistics for each scope.
     */
    public function getForTaxon(int $taxonId): array
    {
        $scopes = [];
        foreach (db_connect()->table('taxon_stats')->where('taxon_id', $taxonId)->get()->getResultArray() as $row) {
            $key = $row['geographic_region_id'] === null ? 'global' : (string) $row['geographic_region_id'];
            $scopes[$key] = $row;
        }

        return $scopes;
    }

    /**
     * Validate a species-only form, including active region keys and required rationale.
     *
     * @param int $taxonId Species primary key.
     * @param array<string, mixed> $input Posted rarity_status and rarity_status_comment arrays.
     * @return array<string|int, array{status: string|null, comment: string|null}> Normalized decisions.
     * @throws InvalidArgumentException If the taxon, scope, status or rationale is invalid.
     */
    public function validateInput(int $taxonId, array $input): array
    {
        $db = db_connect();
        $taxon = $db->table('taxa')->select('id, species_id')->where('id', $taxonId)
            ->where('deleted_at', null)->get()->getRowArray();
        if ($taxon === null || (int) $taxon['species_id'] !== $taxonId) {
            throw new InvalidArgumentException('Rarity status can only be set for a species.');
        }
        $statuses = $input['rarity_status'] ?? null;
        $comments = $input['rarity_status_comment'] ?? [];
        if (! is_array($statuses) || ! is_array($comments)) {
            throw new InvalidArgumentException('Enter a valid rarity status for each scope.');
        }
        $allowed = ['global' => true];
        foreach ($db->table('geographic_regions')->select('id')->where('deleted_at', null)->get()->getResultArray() as $region) {
            $allowed[(string) $region['id']] = true;
        }
        foreach (array_keys($comments) as $key) {
            if (! isset($allowed[$key]) || ! array_key_exists($key, $statuses)) {
                throw new InvalidArgumentException('A rarity rationale was submitted for an invalid scope.');
            }
        }
        $scopes = [];
        foreach ($statuses as $key => $status) {
            $comment = $comments[$key] ?? '';
            if (! isset($allowed[$key]) || ! in_array($status, ['', 'common', 'rare'], true)
                || ! is_string($comment) || strlen($comment) > 65535) {
                throw new InvalidArgumentException('Enter a valid rarity status and rationale for each scope.');
            }
            if ($status !== '' && trim($comment) === '') {
                throw new InvalidArgumentException('A rationale is required when rarity status is set.');
            }
            $scopes[$key] = [
                'status' => $status === '' ? null : $status,
                'comment' => $status === '' ? null : trim($comment),
            ];
        }

        return $scopes;
    }

    /**
     * Save validated decisions atomically without changing measurements or key-species fields.
     *
     * @param int $taxonId Species primary key.
     * @param array<string, mixed> $input Posted rarity arrays, validated again before writing.
     * @throws InvalidArgumentException If input is invalid.
     * @throws RuntimeException If duplicate scopes exist or persistence fails.
     */
    public function saveForTaxon(int $taxonId, array $input): void
    {
        $scopes = $this->validateInput($taxonId, $input);
        $db = db_connect();
        $db->transException(true)->transBegin();
        try {
            foreach ($scopes as $key => $scope) {
                $regionId = $key === 'global' ? null : (int) $key;
                $rows = $db->table('taxon_stats')->where('taxon_id', $taxonId)
                    ->where('geographic_region_id', $regionId)->get()->getResultArray();
                if (count($rows) > 1) {
                    throw new RuntimeException('Duplicate taxon statistics rows prevent saving rarity status.');
                }
                $existing = $rows[0] ?? null;
                $values = [
                    'rarity_status' => $scope['status'],
                    'rarity_status_comment' => $scope['comment'],
                    'rarity_status_decision_date' => $scope['status'] === null ? null
                        : (($existing['rarity_status'] ?? null) === $scope['status']
                            ? $existing['rarity_status_decision_date'] : date('Y-m-d')),
                ];
                if ($existing !== null) {
                    $db->table('taxon_stats')->where('id', $existing['id'])->update($values);
                } elseif ($scope['status'] !== null) {
                    $db->table('taxon_stats')->insert($values + [
                        'uuid' => TaxonStatsIdentity::uuidForScope($taxonId, $regionId),
                        'taxon_id' => $taxonId,
                        'geographic_region_id' => $regionId,
                        'occurrences_count' => 0,
                        'grid_square_count' => 0,
                        'first_recorder' => '',
                        'last_recorder' => '',
                        'first_verified_recorder' => '',
                        'last_verified_recorder' => '',
                    ]);
                }
            }
            if (! $db->transStatus() || ! $db->transCommit()) {
                throw new RuntimeException('Rarity decisions could not be saved.');
            }
        } catch (Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }
}
