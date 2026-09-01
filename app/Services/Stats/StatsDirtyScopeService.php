<?php

namespace App\Services\Stats;

use App\Models\StatsDirtyScopeModel;
use Config\Import;
use Throwable;

/**
 * Enqueues and consumes deduplicated scopes affected by occurrence changes.
 */
class StatsDirtyScopeService
{
    /** @var string */
    public const TAXON_YEAR = 'taxon_year';

    /** @var string */
    public const TAXON = 'taxon';

    /**
     * Create the dirty-scope service.
     *
     * @param StatsDirtyScopeModel|null $model Queue model, resolved when null.
     */
    public function __construct(private readonly ?StatsDirtyScopeModel $model = null)
    {
    }

    /**
     * Enqueue old and current global/region scopes for changed occurrences.
     *
     * @param array<int, array<string, mixed>> $changes Occurrence change snapshots.
     * @return int Number of newly queued scopes.
     */
    public function enqueueOccurrenceChanges(array $changes): int
    {
        $scopes = [];

        foreach ($changes as $change) {
            foreach ([
                ['row' => $change['old'] ?? null, 'regions' => $change['old_region_ids'] ?? []],
                ['row' => $change['new'] ?? null, 'regions' => []],
            ] as $snapshot) {
                if (! is_array($snapshot['row'] ?? null)) {
                    continue;
                }

                $scopes = array_merge($scopes, $this->scopesForOccurrence(
                    $snapshot['row'],
                    (array) $snapshot['regions'],
                ));
            }
        }

        return $this->enqueue($scopes);
    }

    /**
     * Enqueue current region scopes after geographic memberships are rebuilt.
     *
     * @param array<int, int> $occurrenceIds Changed occurrence IDs.
     * @return int Number of newly queued scopes.
     */
    public function enqueueCurrentOccurrenceScopes(array $occurrenceIds): int
    {
        $occurrenceIds = array_values(array_unique(array_filter(
            array_map('intval', $occurrenceIds),
            static fn (int $id): bool => $id > 0,
        )));

        if ($occurrenceIds === []) {
            return 0;
        }

        $db = db_connect();
        $rows = $db->table('occurrences o')
            ->select('o.*')
            ->whereIn('o.id', $occurrenceIds)
            ->get()
            ->getResultArray();
        $regions = $db->table('geographic_regions_occurrences')
            ->select('occurrence_id, geographic_region_id')
            ->whereIn('occurrence_id', $occurrenceIds)
            ->get()
            ->getResultArray();
        $regionsByOccurrence = [];

        foreach ($regions as $region) {
            $regionsByOccurrence[(int) $region['occurrence_id']][] = (int) $region['geographic_region_id'];
        }

        $scopes = [];
        foreach ($rows as $row) {
            $scopes = array_merge($scopes, $this->scopesForOccurrence(
                $row,
                $regionsByOccurrence[(int) $row['id']] ?? [],
            ));
        }

        return $this->enqueue($scopes);
    }

    /**
     * Enqueue scopes for occurrences belonging to a taxon or its projections.
     *
     * @param int $taxonId Taxon identifier whose occurrence scopes may change.
     * @return int Number of newly queued scopes.
     */
    public function enqueueTaxonOccurrenceScopes(int $taxonId): int
    {
        if ($taxonId <= 0) {
            return 0;
        }

        $conditions = ['taxon_id' => $taxonId];
        foreach ($this->reportingColumns() as $column) {
            $conditions[$column] = $taxonId;
        }

        $builder = db_connect()->table('occurrences')->select('id');
        $first = true;
        foreach ($conditions as $column => $value) {
            if ($first) {
                $builder->where($column, $value);
                $first = false;
            } else {
                $builder->orWhere($column, $value);
            }
        }

        $occurrenceIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $builder->get()->getResultArray(),
        );

        return $this->enqueueCurrentOccurrenceScopes($occurrenceIds);
    }

    /**
     * Determine whether a statistic type has queued or initial-population work.
     *
     * @param string $statType Queue type.
     * @return bool True when the type should be selected for processing.
     */
    public function hasWork(string $statType): bool
    {
        try {
            $model = $this->model();
            if ($model->where('stat_type', $statType)->countAllResults() > 0) {
                return true;
            }

            $table = $statType === self::TAXON_YEAR ? 'taxon_year_stats' : 'taxon_stats';

            return ! db_connect()->tableExists($table)
                || db_connect()->table($table)->countAllResults() === 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Return the oldest queued scopes for a statistic type.
     *
     * @param string $statType Queue type.
     * @param int    $limit Maximum number of scopes.
     * @return array<int, array<string, mixed>> Queue rows.
     */
    public function next(string $statType, int $limit): array
    {
        return $this->model()
            ->where('stat_type', $statType)
            ->orderBy('id', 'ASC')
            ->findAll(max(1, $limit));
    }

    /**
     * Acknowledge successfully processed queue rows.
     *
     * @param array<int, int> $ids Queue row IDs.
     * @return void
     */
    public function acknowledge(array $ids): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids !== []) {
            $this->model()->whereIn('id', $ids)->delete();
        }
    }

    /**
     * Return whether more work remains after a bounded run.
     *
     * @param string $statType Queue type.
     * @return bool True when at least one dirty scope remains.
     */
    public function hasDirtyScopes(string $statType): bool
    {
        return $this->model()->where('stat_type', $statType)->countAllResults() > 0;
    }

    /**
     * Enqueue unique scope payloads.
     *
     * @param array<int, array<string, mixed>> $scopes Scope payloads.
     * @return int Number of newly queued scopes.
     */
    private function enqueue(array $scopes): int
    {
        if ($scopes === []) {
            return 0;
        }

        $model = $this->model();
        $scopesByKey = [];
        foreach ($scopes as $scope) {
            $scope['scope_key'] = $this->scopeKey($scope);
            $scopesByKey[$scope['scope_key']] = $scope;
        }
        $keys = array_keys($scopesByKey);
        $existing = $model->select('scope_key')->whereIn('scope_key', $keys)->findAll();
        foreach ($existing as $row) {
            unset($scopesByKey[(string) $row['scope_key']]);
        }

        if ($scopesByKey === []) {
            return 0;
        }

        $model->insertBatch(array_values($scopesByKey));

        return count($scopesByKey);
    }

    /**
     * Expand one occurrence snapshot into exact and reporting statistic scopes.
     *
     * @param array<string, mixed> $row Occurrence snapshot.
     * @param array<int, mixed>    $regionIds Geographic region IDs.
     * @return array<int, array<string, mixed>> Expanded queue payloads.
     */
    private function scopesForOccurrence(array $row, array $regionIds): array
    {
        $taxonId = (int) ($row['taxon_id'] ?? 0);
        if ($taxonId <= 0) {
            return [];
        }

        $projections = [];
        $rankColumns = $this->reportingColumns();
        $rankIsReporting = $this->taxonReportingStates([$taxonId]);
        if (($rankIsReporting[$taxonId] ?? false) === false) {
            $projections['exact'] = $taxonId;
        }
        foreach ($rankColumns as $column) {
            $projectionTaxonId = (int) ($row[$column] ?? 0);
            if ($projectionTaxonId > 0) {
                $projections[$column] = $projectionTaxonId;
            }
        }

        $regions = [null];
        foreach ($regionIds as $regionId) {
            $regionId = (int) $regionId;
            if ($regionId > 0) {
                $regions[] = $regionId;
            }
        }
        $regions = array_values(array_unique($regions, SORT_REGULAR));
        $year = $this->occurrenceYear($row);
        $scopes = [];

        foreach ($projections as $projection => $projectionTaxonId) {
            foreach ($regions as $regionId) {
                $scopes[] = [
                    'stat_type' => self::TAXON,
                    'projection' => $projection,
                    'taxon_id' => $projectionTaxonId,
                    'geographic_region_id' => $regionId,
                    'year' => null,
                ];
                if ($year !== null) {
                    $scopes[] = [
                        'stat_type' => self::TAXON_YEAR,
                        'projection' => $projection,
                        'taxon_id' => $projectionTaxonId,
                        'geographic_region_id' => $regionId,
                        'year' => $year,
                    ];
                }
            }
        }

        return $scopes;
    }

    /**
     * Return whether each taxon is a reporting taxon.
     *
     * @param array<int, int> $taxonIds Taxon IDs.
     * @return array<int, bool> Reporting flags keyed by taxon ID.
     */
    private function taxonReportingStates(array $taxonIds): array
    {
        if ($taxonIds === []) {
            return [];
        }

        $rows = db_connect()->table('taxa t')
            ->select('t.id, tr.is_reporting')
            ->join('taxon_ranks tr', 'tr.id = t.taxon_rank_id')
            ->whereIn('t.id', $taxonIds)
            ->get()
            ->getResultArray();
        $states = [];
        foreach ($rows as $row) {
            $states[(int) $row['id']] = (int) ($row['is_reporting'] ?? 0) === 1;
        }

        return $states;
    }

    /**
     * Extract the calendar year from an occurrence snapshot.
     *
     * @param array<string, mixed> $row Occurrence snapshot.
     * @return int|null Calendar year, or null when no valid date exists.
     */
    private function occurrenceYear(array $row): ?int
    {
        $date = trim((string) ($row['from_date'] ?? $row['to_date'] ?? ''));
        $year = (int) substr($date, 0, 4);

        return $year > 0 ? $year : null;
    }

    /**
     * Build the stable deduplication key for a scope.
     *
     * @param array<string, mixed> $scope Scope payload.
     * @return string Stable queue key.
     */
    private function scopeKey(array $scope): string
    {
        return implode('|', [
            (string) $scope['stat_type'],
            (string) $scope['projection'],
            (string) $scope['taxon_id'],
            $scope['geographic_region_id'] === null ? 'global' : (string) $scope['geographic_region_id'],
            $scope['year'] === null ? 'all' : (string) $scope['year'],
        ]);
    }

    /**
     * Return configured physical reporting-rank columns.
     *
     * @return array<int, string> Normalised occurrence columns.
     */
    private function reportingColumns(): array
    {
        $ranks = config(Import::class)->taxonRanks ?? [];
        $ranks = is_array($ranks) ? $ranks : explode(',', (string) $ranks);

        return array_values(array_filter(array_map(static function ($rank): string {
            if (! is_scalar($rank)) {
                return '';
            }
            $column = preg_replace('/[^a-z0-9]+/i', '_', strtolower(trim((string) $rank)));

            return trim((string) $column, '_') . '_id';
        }, $ranks)));
    }

    /**
     * Resolve the queue model.
     *
     * @return StatsDirtyScopeModel Queue model.
     */
    private function model(): StatsDirtyScopeModel
    {
        return $this->model ?? model(StatsDirtyScopeModel::class);
    }
}