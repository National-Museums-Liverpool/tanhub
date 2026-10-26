<?php

namespace App\Services\Stats;

use RuntimeException;

/**
 * Calculates species occupancy measurements after complete aggregate batches.
 *
 * Rankings use every eligible peer, not just changed taxa. Curated rarity decisions
 * are deliberately excluded: they describe expert opinion, not measured coverage.
 */
class TaxonOccupancyService
{
    /**
     * Refresh global and regional measurements without modifying curated fields.
     *
     * A species is its own species projection, as in the existing rarity calculation.
     * Missing mapped coverage leaves percentages null and emits a warning. Coverage
     * exceeding the mapped denominator fails explicitly so work remains retryable.
     *
     * @throws RuntimeException If occupied squares exceed the mapped denominator.
     */
    public function refresh(): void
    {
        $db = db_connect();
        $rows = $db->table('taxon_stats ts')
            ->select('ts.id, ts.geographic_region_id, ts.occurrences_count, ts.grid_square_count,
                t.id AS active_taxon_id, t.species_id, t.rarity_group_name, gr.id AS active_region_id')
            ->join('taxa t', 't.id = ts.taxon_id AND t.blocked = 0 AND t.deleted_at IS NULL', 'left')
            ->join('geographic_regions gr', 'gr.id = ts.geographic_region_id AND gr.deleted_at IS NULL', 'left')
            ->get()->getResultArray();
        $prefix = $db->getPrefix();
        $denominators = ['global' => 0];
        $coverage = $db->query(
            'SELECT gss.geographic_region_id, COUNT(DISTINCT UPPER(TRIM(gss.square))) AS square_count
             FROM ' . $prefix . 'grid_square_stats gss
             INNER JOIN ' . $prefix . 'geographic_regions gr
                 ON gr.id = gss.geographic_region_id AND gr.deleted_at IS NULL
             WHERE TRIM(gss.square) <> ""
             GROUP BY gss.geographic_region_id'
        )->getResultArray();
        foreach ($coverage as $scope) {
            $denominators[(string) $scope['geographic_region_id']] = (int) $scope['square_count'];
        }
        $global = $db->query(
            'SELECT COUNT(DISTINCT UPPER(TRIM(gss.square))) AS square_count
             FROM ' . $prefix . 'grid_square_stats gss
             INNER JOIN ' . $prefix . 'geographic_regions gr
                 ON gr.id = gss.geographic_region_id AND gr.deleted_at IS NULL
             WHERE TRIM(gss.square) <> ""'
        )->getRowArray();
        $denominators['global'] = (int) $global['square_count'];

        $updates = [];
        $groups = [];
        $missingCoverage = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $updates[$id] = [
                'id' => $id,
                'occupied_square_score' => null,
                'occupied_square_percentage' => null,
            ];
            if ($row['active_taxon_id'] === null
                || (int) $row['species_id'] !== (int) $row['active_taxon_id']
                || (int) $row['occurrences_count'] === 0
                || ($row['geographic_region_id'] !== null && $row['active_region_id'] === null)) {
                continue;
            }

            $scope = $row['geographic_region_id'] === null ? 'global' : (string) $row['geographic_region_id'];
            $total = $denominators[$scope] ?? 0;
            $occupied = (int) $row['grid_square_count'];
            if ($total === 0) {
                $missingCoverage[$scope] = true;
            } elseif ($occupied > $total) {
                throw new RuntimeException('Occupied squares exceed mapped coverage for taxon stats row ' . $id . '.');
            } else {
                $updates[$id]['occupied_square_percentage'] = round(100 * $occupied / $total, 4);
            }

            $group = trim((string) $row['rarity_group_name']);
            if ($occupied > 0 && $group !== '') {
                $groups[$scope][$group][] = ['id' => $id, 'count' => $occupied];
            }
        }
        foreach (array_keys($missingCoverage) as $scope) {
            log_message('warning', 'Taxon occupancy percentage unavailable: no mapped squares for scope ' . $scope . '.');
        }
        foreach ($groups as $scopeGroups) {
            foreach ($scopeGroups as $members) {
                usort($members, static fn (array $a, array $b): int => $a['count'] <=> $b['count']);
                $size = count($members);
                $rank = 0;
                $previous = null;
                foreach ($members as $index => $member) {
                    if ($member['count'] !== $previous) {
                        $rank = $index;
                        $previous = $member['count'];
                    }
                    $updates[$member['id']]['occupied_square_score'] = $size === 1
                        ? 50.0
                        : round(100 * $rank / ($size - 1), 4);
                }
            }
        }
        foreach (array_chunk(array_values($updates), 500) as $chunk) {
            $db->table('taxon_stats')->updateBatch($chunk, 'id');
        }
    }
}
