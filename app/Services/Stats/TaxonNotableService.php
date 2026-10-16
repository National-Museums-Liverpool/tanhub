<?php

namespace App\Services\Stats;

use CodeIgniter\Database\BaseConnection;
use RuntimeException;
use Throwable;

/**
 * Reads and saves curated key-species decisions in taxon statistics rows.
 */
class TaxonNotableService
{
    /**
     * Get the saved key-species values for each scope of a taxon.
     *
     * @param int $taxonId Taxon identifier.
     * @return array<string, array{notable: bool|null, notable_comment: string|null, notable_decision_date: string|null}>
     */
    public function getForTaxon(int $taxonId): array
    {
        $rows = db_connect()
            ->table('taxon_stats')
            ->select('geographic_region_id, notable, notable_comment, notable_decision_date')
            ->where('taxon_id', $taxonId)
            ->get()
            ->getResultArray();

        $scopes = [];
        foreach ($rows as $row) {
            $scopeKey = $row['geographic_region_id'] === null
                ? 'global'
                : (string) (int) $row['geographic_region_id'];

            $scopes[$scopeKey] = [
                'notable' => $row['notable'] === null ? null : (bool) $row['notable'],
                'notable_comment' => $row['notable_comment'] === null
                    ? null
                    : (string) $row['notable_comment'],
                'notable_decision_date' => $row['notable_decision_date'] === null
                    ? null
                    : (string) $row['notable_decision_date'],
            ];
        }

        return $scopes;
    }

    /**
     * Save decisions for the supplied global and regional scopes atomically.
     *
     * @param int $taxonId Taxon identifier.
     * @param array<string|int, array{notable: bool|null, comment: string|null}> $scopes Scope
     *        decisions keyed by `global` or geographic region ID.
     * @return void
     * @throws RuntimeException If a scope is invalid or duplicate statistics rows exist.
     */
    public function saveForTaxon(int $taxonId, array $scopes): void
    {
        $db = db_connect();
        $db->transBegin();

        try {
            foreach ($scopes as $scopeKey => $scope) {
                $regionId = $this->regionId($scopeKey);
                $rows = $this->scopeQuery($db, $taxonId, $regionId)
                    ->get()
                    ->getResultArray();

                if (count($rows) > 1) {
                    throw new RuntimeException('Duplicate taxon statistics rows prevent saving this decision.');
                }

                $existing = $rows[0] ?? null;
                $notable = $scope['notable'];
                $comment = $notable === null ? null : $scope['comment'];
                $existingNotable = $existing === null || $existing['notable'] === null
                    ? null
                    : (bool) $existing['notable'];
                $decisionDate = $notable === null
                    ? null
                    : ($existingNotable === $notable
                        ? ($existing['notable_decision_date'] ?? null)
                        : date('Y-m-d'));

                if ($existing !== null) {
                    $db->table('taxon_stats')
                        ->where('id', (int) $existing['id'])
                        ->update([
                            'notable' => $notable === null ? null : (int) $notable,
                            'notable_comment' => $comment,
                            'notable_decision_date' => $decisionDate,
                        ]);
                } elseif ($notable !== null) {
                    $this->insertDecisionRow($db, $taxonId, $regionId, $notable, $comment, $decisionDate);
                }
            }

            if (! $db->transStatus() || ! $db->transCommit()) {
                throw new RuntimeException('The key-species decisions could not be saved.');
            }
        } catch (Throwable $exception) {
            $db->transRollback();
            throw $exception;
        }
    }

    /**
     * Find the statistics row for a taxon's global or regional scope.
     *
     * @param BaseConnection $db Database connection.
     * @param int $taxonId Taxon identifier.
     * @param int|null $regionId Geographic region identifier, or null for global.
     * @return object Query builder for the scope row.
     */
    private function scopeQuery(BaseConnection $db, int $taxonId, ?int $regionId): object
    {
        $builder = $db->table('taxon_stats')->where('taxon_id', $taxonId);

        return $regionId === null
            ? $builder->where('geographic_region_id', null)
            : $builder->where('geographic_region_id', $regionId);
    }

    /**
     * Convert a validated form scope key to a region identifier.
     *
     * @param string|int $scopeKey Form scope key.
     * @return int|null Geographic region identifier, or null for global.
     * @throws RuntimeException If the scope key is invalid.
     */
    private function regionId(string|int $scopeKey): ?int
    {
        if ($scopeKey === 'global') {
            return null;
        }

        $value = (string) $scopeKey;
        if ($value === '' || ! ctype_digit($value) || (int) $value <= 0) {
            throw new RuntimeException('An invalid geographic region was submitted.');
        }

        return (int) $value;
    }

    /**
     * Create a statistics row for a scope that has no derived statistics yet.
     *
     * @param BaseConnection $db Database connection.
     * @param int $taxonId Taxon identifier.
     * @param int|null $regionId Geographic region identifier, or null for global.
     * @param bool $notable Key-species decision.
     * @param string|null $comment Current decision rationale.
     * @param string|null $decisionDate Date of the current decision.
     * @return void
     */
    private function insertDecisionRow(
        BaseConnection $db,
        int $taxonId,
        ?int $regionId,
        bool $notable,
        ?string $comment,
        ?string $decisionDate,
    ): void {
        $db->table('taxon_stats')->insert([
            'uuid' => TaxonStatsIdentity::uuidForScope($taxonId, $regionId),
            'taxon_id' => $taxonId,
            'geographic_region_id' => $regionId,
            'occurrences_count' => 0,
            'grid_square_count' => 0,
            'frequency_trend' => null,
            'frequency_trend_state' => null,
            'first_record_date' => null,
            'last_record_date' => null,
            'first_recorder' => '',
            'last_recorder' => '',
            'first_verified_record_date' => null,
            'last_verified_record_date' => null,
            'first_verified_recorder' => '',
            'last_verified_recorder' => '',
            'notable' => (int) $notable,
            'notable_comment' => $comment,
            'notable_decision_date' => $decisionDate,
        ]);
    }

}
