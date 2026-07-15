<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * API endpoints for occurrences.
 */
class Occurrences extends ApiController
{
    /**
     * List occurrences.
     */
    public function index(): ResponseInterface
    {
        $db = db_connect();
        $prefix = $db->getPrefix();
        $includes = $this->getIncludes();

        if ($includes instanceof ResponseInterface) {
            return $includes;
        }

        $pagination = $this->getPagination();

        if ($pagination instanceof ResponseInterface) {
            return $pagination;
        }

        $sorts = $this->getSorts($this->allowedSorts($includes), 'from_date');

        if ($sorts instanceof ResponseInterface) {
            return $sorts;
        }

        $filters = $this->getFilters($this->allowedFilters($includes));

        if ($filters instanceof ResponseInterface) {
            return $filters;
        }

        $usesIncludedBuilder = $this->usesIncludedBuilder($includes);

        $builder = $usesIncludedBuilder
            ? $this->buildIncludedBuilder($db, $prefix, $includes)
            : $this->buildDefaultBuilder($db, $prefix);

        $normalFilters = [];
        $customFilters = [];

        foreach ($filters as $filter) {
            if (str_starts_with((string) $filter['column'], '__')) {
                $customFilters[] = $filter;
                continue;
            }

            $normalFilters[] = $filter;
        }

        $this->applyFilters($builder, $normalFilters);

        foreach ($customFilters as $filter) {
            $this->applyCustomFilter(
                $builder,
                $filter,
                $prefix,
                $db,
                'id',
                'taxon_id',
                'taxon_name_id',
                'data_source_id',
            );
        }

        $this->applySorts($builder, $sorts);

        $total = (clone $builder)->countAllResults();

        $data = $builder
            ->limit($pagination['limit'], $pagination['offset'])
            ->get()
            ->getResultArray();

        return $this->respondList($data, $total, $pagination['limit'], $pagination['offset']);
    }

    /**
     * Return one occurrence by unique key.
     */
    public function show(string $uniqueKey): ResponseInterface
    {
        $db = db_connect();
        $prefix = $db->getPrefix();
        $includes = $this->getIncludes();

        if ($includes instanceof ResponseInterface) {
            return $includes;
        }

        $usesIncludedBuilder = $this->usesIncludedBuilder($includes);

        $builder = $usesIncludedBuilder
            ? $this->buildIncludedBuilder($db, $prefix, $includes)
            : $this->buildDefaultBuilder($db, $prefix);

        $item = $builder
            ->where('unique_key', $uniqueKey)
            ->get()
            ->getRowArray();

        if ($item === null) {
            return $this->respondProblem(404, 'Resource not found', "No occurrence exists for unique_key '{$uniqueKey}'.");
        }

        return $this->respondItem($item);
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function applyCustomFilter(
        $builder,
        array $filter,
        string $prefix,
        $db,
        string $occurrenceIdColumn = 'id',
        string $taxonIdColumn = 'taxon_id',
        string $taxonNameIdColumn = 'taxon_name_id',
        string $dataSourceIdColumn = 'data_source_id',
    ): void {
        $column = (string) $filter['column'];
        $operator = (string) $filter['operator'];
        $value = $filter['value'];

        if ($column === '__taxon_identifier__') {
            $this->applySubqueryFilter($builder, $operator, $value, $taxonIdColumn, 'id', $prefix . 'taxa', 'taxon_identifier', $db);
            return;
        }

        if ($column === '__taxon_name_uuid__') {
            $this->applySubqueryFilter($builder, $operator, $value, $taxonNameIdColumn, 'id', $prefix . 'taxon_names', 'uuid', $db);
            return;
        }

        if ($column === '__data_source_abbr__') {
            $this->applySubqueryFilter($builder, $operator, $value, $dataSourceIdColumn, 'id', $prefix . 'data_sources', 'abbr', $db);
            return;
        }

        if ($column === '__higher_geography_identifier__') {
            if ($operator === 'eq') {
                $builder->where($occurrenceIdColumn . ' IN (SELECT occurrence_id FROM ' . $prefix . 'geographic_regions_occurrences WHERE geographic_region_id IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE higher_geography_identifier = ' . $db->escape($value) . ' AND deleted_at IS NULL))', null, false);
                return;
            }

            if ($operator === 'in') {
                $values = is_array($value)
                    ? $value
                    : array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $v): bool => $v !== '');
                $escaped = array_map(static fn ($v): string => $db->escape((string) $v), $values);

                if ($escaped !== []) {
                    $builder->where($occurrenceIdColumn . ' IN (SELECT occurrence_id FROM ' . $prefix . 'geographic_regions_occurrences WHERE geographic_region_id IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE higher_geography_identifier IN (' . implode(',', $escaped) . ') AND deleted_at IS NULL))', null, false);
                }

                return;
            }

            if ($operator === 'contains') {
                $like = '%' . $db->escapeLikeString(strtolower((string) $value)) . '%';
                $builder->where($occurrenceIdColumn . ' IN (SELECT occurrence_id FROM ' . $prefix . 'geographic_regions_occurrences WHERE geographic_region_id IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE LOWER(CAST(higher_geography_identifier AS TEXT)) LIKE ' . $db->escape($like) . " ESCAPE '!' AND deleted_at IS NULL))", null, false);
                return;
            }

            if ($operator === 'gte') {
                $builder->where($occurrenceIdColumn . ' IN (SELECT occurrence_id FROM ' . $prefix . 'geographic_regions_occurrences WHERE geographic_region_id IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE higher_geography_identifier >= ' . $db->escape($value) . ' AND deleted_at IS NULL))', null, false);
                return;
            }

            if ($operator === 'lte') {
                $builder->where($occurrenceIdColumn . ' IN (SELECT occurrence_id FROM ' . $prefix . 'geographic_regions_occurrences WHERE geographic_region_id IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE higher_geography_identifier <= ' . $db->escape($value) . ' AND deleted_at IS NULL))', null, false);
            }
        }
    }

    /**
     * @param array<string, bool> $includes
     * @return array<string, string>
     */
    private function allowedSorts(array $includes): array
    {
        $usesIncludedBuilder = $this->usesIncludedBuilder($includes);

        $sorts = [
            'unique_key' => 'unique_key',
            'from_date' => 'from_date',
            'to_date' => 'to_date',
            'grid_ref' => 'grid_ref',
            'grid_ref_2km' => 'grid_ref_2km',
            'locality' => 'locality',
            'recorded_by' => 'recorded_by',
            'identified_by' => 'identified_by',
            'identification_verification_status' => 'identification_verification_status',
            'sex' => 'sex',
            'life_stage' => 'life_stage',
            'organism_quantity' => 'organism_quantity',
            'data_source_abbr' => 'data_source_abbr',
        ];

        if ($this->hasInclude($includes, 'grid_square_stats')) {
            $sorts['easting'] = 'easting';
            $sorts['northing'] = 'northing';
            $sorts['lat'] = 'lat';
            $sorts['lon'] = 'lon';
        }

        if (! $this->hasInclude($includes, 'taxon')) {
            return $sorts;
        }

        $sorts['taxon_identifier'] = 'taxon_identifier';
        $sorts['scientific_name'] = 'scientific_name';
        $sorts['scientific_name_authorship'] = 'scientific_name_authorship';
        $sorts['scientific_name_identifier'] = 'scientific_name_identifier';
        $sorts['vernacular_name'] = 'vernacular_name';

        if ($this->hasInclude($includes, 'taxon_name')) {
            $sorts['taxon_name_uuid'] = 'taxon_name_uuid';
            $sorts['given_name'] = 'given_name';
        }

        if ($this->hasInclude($includes, 'taxon_rank')) {
            $sorts['taxon_rank'] = 'taxon_rank';
        }

        if ($this->hasInclude($includes, 'taxon_group')) {
            $sorts['taxon_group_external_key'] = 'taxon_group_external_key';
        }

        if ($this->hasInclude($includes, 'parent_taxa')) {
            foreach ($this->dynamicRankAliases() as $alias) {
                $sorts[$alias . '_scientific_name'] = $alias . '_scientific_name';
                $sorts[$alias . '_vernacular_name'] = $alias . '_vernacular_name';
            }
        }

        return $sorts;
    }

    /**
     * @param array<string, bool> $includes
     * @return array<string, string>
     */
    private function allowedFilters(array $includes): array
    {
        $usesIncludedBuilder = $this->usesIncludedBuilder($includes);

        $filters = [
            'unique_key' => 'unique_key',
            'taxon_identifier' => $this->hasInclude($includes, 'taxon') ? 'taxon_identifier' : '__taxon_identifier__',
            'taxon_name_uuid' => $this->hasInclude($includes, 'taxon_name') ? 'taxon_name_uuid' : '__taxon_name_uuid__',
            'from_date' => 'from_date',
            'to_date' => 'to_date',
            'grid_ref' => 'grid_ref',
            'grid_ref_2km' => 'grid_ref_2km',
            'locality' => 'locality',
            'recorded_by' => 'recorded_by',
            'identified_by' => 'identified_by',
            'identification_verification_status' => 'identification_verification_status',
            'sex' => 'sex',
            'life_stage' => 'life_stage',
            'organism_quantity' => 'organism_quantity',
            'higher_geography_identifier' => '__higher_geography_identifier__',
        ];

        if ($this->hasInclude($includes, 'grid_square_stats')) {
            $filters['easting'] = 'easting';
            $filters['northing'] = 'northing';
            $filters['lat'] = 'lat';
            $filters['lon'] = 'lon';
        }

        if (! $this->hasInclude($includes, 'taxon')) {
            return $filters;
        }

        $filters['scientific_name'] = 'scientific_name';
        $filters['scientific_name_authorship'] = 'scientific_name_authorship';
        $filters['scientific_name_identifier'] = 'scientific_name_identifier';
        $filters['vernacular_name'] = 'vernacular_name';

        if ($this->hasInclude($includes, 'taxon_name')) {
            $filters['given_name'] = 'given_name';
        }

        if ($this->hasInclude($includes, 'taxon_rank')) {
            $filters['taxon_rank'] = 'taxon_rank';
        }

        if ($this->hasInclude($includes, 'taxon_group')) {
            $filters['taxon_group_external_key'] = 'taxon_group_external_key';
        }

        if ($this->hasInclude($includes, 'parent_taxa')) {
            foreach ($this->dynamicRankAliases() as $alias) {
                $filters[$alias . '_scientific_name'] = $alias . '_scientific_name';
                $filters[$alias . '_vernacular_name'] = $alias . '_vernacular_name';
            }
        }

        return $filters;
    }

    private function buildDefaultBuilder($db, string $prefix)
    {
        return $db->table('occurrences')
            ->select(
                'unique_key,
                (SELECT taxon_identifier FROM ' . $prefix . 'taxa WHERE id = ' . $prefix . 'occurrences.taxon_id) AS taxon_identifier,
                (SELECT uuid FROM ' . $prefix . 'taxon_names WHERE id = ' . $prefix . 'occurrences.taxon_name_id) AS taxon_name_uuid,
                from_date,
                to_date,
                grid_ref,
                grid_ref_2km,
                locality,
                recorded_by,
                identified_by,
                identification_verification_status,
                sex,
                life_stage,
                organism_quantity,
                (SELECT abbr FROM ' . $prefix . 'data_sources WHERE id = ' . $prefix . 'occurrences.data_source_id) AS data_source_abbr,
                (SELECT MIN(gr.higher_geography_identifier)
                    FROM ' . $prefix . 'geographic_regions_occurrences gro
                    INNER JOIN ' . $prefix . 'geographic_regions gr
                        ON gr.id = gro.geographic_region_id
                    WHERE gro.occurrence_id = ' . $prefix . 'occurrences.id
                        AND gr.deleted_at IS NULL) AS higher_geography_identifier',
                false
            )
            ->where($prefix . 'occurrences.deleted_at IS NULL', null, false)
            ->where($prefix . 'occurrences.blocked = 0', null, false);
    }

    /**
     * @param array<string, bool> $includes
     */
    private function buildIncludedBuilder($db, string $prefix, array $includes)
    {
        $builder = $db->table('occurrences')
            ->select(
                'unique_key,
                (SELECT taxon_identifier FROM ' . $prefix . 'taxa WHERE id = taxon_id) AS taxon_identifier,
                (SELECT uuid FROM ' . $prefix . 'taxon_names WHERE id = taxon_name_id) AS taxon_name_uuid,
                from_date,
                to_date,
                grid_ref,
                grid_ref_2km,
                locality,
                recorded_by,
                identified_by,
                identification_verification_status,
                sex,
                life_stage,
                organism_quantity,
                (SELECT abbr FROM ' . $prefix . 'data_sources WHERE id = data_source_id) AS data_source_abbr,
                (SELECT MIN(gr.higher_geography_identifier)
                    FROM ' . $prefix . 'geographic_regions_occurrences gro
                    INNER JOIN ' . $prefix . 'geographic_regions gr
                        ON gr.id = gro.geographic_region_id
                    WHERE gro.occurrence_id = id
                        AND gr.deleted_at IS NULL) AS higher_geography_identifier',
                false
            )
            ->where($prefix . 'occurrences.deleted_at IS NULL', null, false)
            ->where($prefix . 'occurrences.blocked = 0', null, false);

        if ($this->hasInclude($includes, 'grid_square_stats')) {
            $builder->join($prefix . 'grid_square_stats gss', 'gss.square = ' . $prefix . 'occurrences.grid_ref_2km', 'left');
            $builder->select('gss.easting, gss.northing, gss.lat, gss.lon', false);
        }

        if ($this->hasInclude($includes, 'taxon')) {
            $builder->select('(SELECT scientific_name FROM ' . $prefix . 'taxa WHERE id = ' . $prefix . 'occurrences.taxon_id) AS scientific_name', false);
            $builder->select('(SELECT scientific_name_authorship FROM ' . $prefix . 'taxa WHERE id = ' . $prefix . 'occurrences.taxon_id) AS scientific_name_authorship', false);
            $builder->select('(SELECT scientific_name_identifier FROM ' . $prefix . 'taxa WHERE id = ' . $prefix . 'occurrences.taxon_id) AS scientific_name_identifier', false);
            $builder->select('(SELECT vernacular_name FROM ' . $prefix . 'taxa WHERE id = ' . $prefix . 'occurrences.taxon_id) AS vernacular_name', false);
        }

        if ($this->hasInclude($includes, 'taxon_name')) {
            $builder->select('(SELECT name FROM ' . $prefix . 'taxon_names WHERE id = ' . $prefix . 'occurrences.taxon_name_id) AS given_name', false);
            $builder->select('(SELECT uuid FROM ' . $prefix . 'taxon_names WHERE id = ' . $prefix . 'occurrences.taxon_name_id) AS taxon_name_uuid', false);
        }

        if ($this->hasInclude($includes, 'taxon_rank')) {
            $builder->select('(SELECT tr.rank FROM ' . $prefix . 'taxon_ranks tr WHERE tr.id = (SELECT t.taxon_rank_id FROM ' . $prefix . 'taxa t WHERE t.id = ' . $prefix . 'occurrences.taxon_id)) AS taxon_rank', false);
        }

        if ($this->hasInclude($includes, 'taxon_group')) {
            $builder->select('(SELECT tg.external_key FROM ' . $prefix . 'taxon_groups tg WHERE tg.id = (SELECT t.taxon_group_id FROM ' . $prefix . 'taxa t WHERE t.id = ' . $prefix . 'occurrences.taxon_id)) AS taxon_group_external_key', false);
        }

        if ($this->hasInclude($includes, 'parent_taxa')) {
            foreach ($this->dynamicRankAliases() as $alias) {
                $column = $alias . '_id';
                $builder->select('(SELECT scientific_name FROM ' . $prefix . 'taxa WHERE id = ' . $prefix . 'occurrences.' . $column . ') AS ' . $alias . '_scientific_name', false);
                $builder->select('(SELECT vernacular_name FROM ' . $prefix . 'taxa WHERE id = ' . $prefix . 'occurrences.' . $column . ') AS ' . $alias . '_vernacular_name', false);
            }
        }

        return $builder;
    }

    /**
     * @return array<int, string>
     */
    private function dynamicRankAliases(): array
    {
        $ranks = config('Import')->taxonRanks ?? [];
        $ranks = is_array($ranks) ? $ranks : explode(',', (string) $ranks);
        $scalarRanks = array_values(array_filter($ranks, static fn ($rank): bool => is_scalar($rank)));
        $rankStrings = array_map(static fn ($rank): string => (string) $rank, $scalarRanks);

        return $this->resolveAvailableTaxonRankAliases($rankStrings);
    }

    /**
     * @return array<string, bool>|ResponseInterface
     */
    private function getIncludes(): array|ResponseInterface
    {
        $raw = (string) ($this->request->getGet('include') ?? '');

        if (trim($raw) === '') {
            return [];
        }

        $parts = array_filter(array_map('trim', explode(',', strtolower($raw))), static fn (string $item): bool => $item !== '');
        $supported = ['taxon', 'taxon_name', 'taxon_rank', 'taxon_group', 'parent_taxa', 'grid_square_stats'];
        $includes = [];

        foreach ($parts as $part) {
            if (! in_array($part, $supported, true)) {
                return $this->respondProblem(400, 'Invalid include parameter', "Unsupported include value '{$part}'.");
            }

            $includes[$part] = true;
        }

        return $includes;
    }

    /**
     * @param array<string, bool> $includes
     */
    private function hasInclude(array $includes, string $name): bool
    {
        return isset($includes[$name]) && $includes[$name] === true;
    }

    /**
     * @param array<string, bool> $includes
     */
    private function usesIncludedBuilder(array $includes): bool
    {
        return $includes !== [];
    }

    /**
     * Apply eq/in/contains/gte/lte operators through a related-table subquery.
     *
     * @param mixed $value
     */
    private function applySubqueryFilter($builder, string $operator, $value, string $localColumn, string $relatedPk, string $relatedTable, string $relatedField, $db): void
    {
        if ($operator === 'eq') {
            $builder->where($localColumn . ' IN (SELECT ' . $relatedPk . ' FROM ' . $relatedTable . ' WHERE ' . $relatedField . ' = ' . $db->escape($value) . ')', null, false);
            return;
        }

        if ($operator === 'in') {
            $values = is_array($value)
                ? $value
                : array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $v): bool => $v !== '');
            $escaped = array_map(static fn ($v): string => $db->escape((string) $v), $values);

            if ($escaped !== []) {
                $builder->where($localColumn . ' IN (SELECT ' . $relatedPk . ' FROM ' . $relatedTable . ' WHERE ' . $relatedField . ' IN (' . implode(',', $escaped) . '))', null, false);
            }

            return;
        }

        if ($operator === 'contains') {
            $like = '%' . $db->escapeLikeString(strtolower((string) $value)) . '%';
            $builder->where($localColumn . ' IN (SELECT ' . $relatedPk . ' FROM ' . $relatedTable . ' WHERE LOWER(' . $relatedField . ') LIKE ' . $db->escape($like) . " ESCAPE '!')", null, false);
            return;
        }

        if ($operator === 'gte') {
            $builder->where($localColumn . ' IN (SELECT ' . $relatedPk . ' FROM ' . $relatedTable . ' WHERE ' . $relatedField . ' >= ' . $db->escape($value) . ')', null, false);
            return;
        }

        if ($operator === 'lte') {
            $builder->where($localColumn . ' IN (SELECT ' . $relatedPk . ' FROM ' . $relatedTable . ' WHERE ' . $relatedField . ' <= ' . $db->escape($value) . ')', null, false);
        }
    }
}
