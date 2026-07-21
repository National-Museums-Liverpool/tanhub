<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\RawSql;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * API endpoints for occurrences.
 */
class Occurrences extends ApiResourceController
{

    /**
     * List occurrences.
     */
    public function index(): ResponseInterface
    {
        $includes = $this->parseRequestedIncludes();

        if ($includes instanceof ResponseInterface) {
            return $includes;
        }

        $pagination = $this->getPagination();

        if ($pagination instanceof ResponseInterface) {
            return $pagination;
        }

        $sorts = $this->getSorts($this->allowedSorts($includes));

        if ($sorts instanceof ResponseInterface) {
            return $sorts;
        }

        $filters = $this->getFilters($this->allowedFilters($includes));

        if ($filters instanceof ResponseInterface) {
            return $filters;
        }

        $db = db_connect();
        $builder = $this->getBuilder($db, $includes);

        $this->applyFilters($builder, $filters);
        $this->applySorts($builder, $sorts);

        $total = (clone $builder)->countAllResults();

        $data = $builder
            ->limit($pagination['limit'], $pagination['offset'])
            ->get()
            ->getResultArray();

        if ($this->hasInclude($includes, 'geographic-region')) {
            $this->hydrateGeographicRegions($data);
        }

        $this->removeInternalFields($data);

        return $this->respondList($data, $total, $pagination['limit'], $pagination['offset']);
    }

    /**
     * Return a single occurrence by unique key.
     */
    public function show(string $key): ResponseInterface
    {
        $includes = $this->parseRequestedIncludes();

        if ($includes instanceof ResponseInterface) {
            return $includes;
        }

        $db = db_connect();

        $item = $this->getBuilder($db, $includes)
            ->where($this->getDefaultKeyColumn(), $key)
            ->get()
            ->getRowArray();

        if ($item === null) {
            return $this->respondProblem(404, 'Resource not found', "No Occurrences exists for key '{$key}'.");
        }

        $rows = [$item];

        if ($this->hasInclude($includes, 'geographic-region')) {
            $this->hydrateGeographicRegions($rows);
        }

        $this->removeInternalFields($rows);

        return $this->respondItem($rows[0]);
    }

    /**
     * Retrieve list of resources that can be included (joined) in requests.
     *
     * @return string[]
     *   Resource name list.
     */
    protected function getAllowedIncludes(array $requested): array
    {
        $includes = [
            'data-source',
            'geographic-region',
            'grid-square-stats',
            'taxon',
            'taxon-name',
        ];

        if (in_array('taxon', $requested, true)) {
            $includes = array_merge($includes, [
                'taxon-rank',
                'taxon-group',
                'parent-taxa',
            ]);
        }

        return $includes;
    }

    /**
     * Retrieve sortable fields array.
     *
     * @return array<string, string>
     *   Array of field identifiers and their corresponding query columns.
     */
    protected function allowedFields(array $includes = []): array
    {
        $fields = [
            '__occurrence_id' => 'o.id',
            'unique_key' => 'o.unique_key',
            'taxon_identifier' => 't.taxon_identifier',
            'from_date' => 'o.from_date',
            'to_date' => 'o.to_date',
            'grid_ref' => 'o.grid_ref',
            'grid_ref_2km' => 'o.grid_ref_2km',
            'locality' => 'o.locality',
            'recorded_by' => 'o.recorded_by',
            'identified_by' => 'o.identified_by',
            'identification_verification_status' => 'o.identification_verification_status',
            'sex' => 'o.sex',
            'life_stage' => 'o.life_stage',
            'organism_quantity' => 'o.organism_quantity',
            'higher_geography_identifier' => $this->buildHigherGeographyIdentifierSql(),
        ];

        if ($this->hasInclude($includes, 'data-source')) {
            $fields['data_source__abbr'] = 'ds.abbr';
            $fields['data_source__title'] = 'ds.title';
            $fields['data_source__url'] = 'ds.url';
        }

        if ($this->hasInclude($includes, 'grid-square-stats')) {
            $fields['grid_square_stats__easting'] = 'gss.easting';
            $fields['grid_square_stats__northing'] = 'gss.northing';
            $fields['grid_square_stats__lat'] = 'gss.lat';
            $fields['grid_square_stats__lon'] = 'gss.lon';
        }

        if ($this->hasInclude($includes, 'taxon-name')) {
            $fields['taxon_name__uuid'] = 'tn.uuid';
            $fields['taxon_name__name'] = 'tn.name';
            $fields['taxon_name__given_name_identifier'] = 'tn.given_name_identifier';
            $fields['taxon_name__accepted'] = 'tn.accepted';
            $fields['taxon_name__scientific'] = 'tn.scientific';
        }

        // Other include options all depend on taxon.
        if (! $this->hasInclude($includes, 'taxon')) {
            return $fields;
        }

        $fields['taxon__scientific_name'] = 't.scientific_name';
        $fields['taxon__scientific_name_authorship'] = 't.scientific_name_authorship';
        $fields['taxon__scientific_name_identifier'] = 't.scientific_name_identifier';
        $fields['taxon__vernacular_name'] = 't.vernacular_name';

        if ($this->hasInclude($includes, 'taxon-rank')) {
            $fields['taxon_rank__rank'] = 'tr.rank';
            $fields['taxon_rank__abbr'] = 'tr.abbr';
            $fields['taxon_rank__sort_order'] = 'tr.sort_order';
        }

        if ($this->hasInclude($includes, 'taxon-group')) {
            $fields['taxon_group__title'] = 'tg.title';
            $fields['taxon_group__friendly'] = 'tg.friendly';
            $fields['taxon_group__external_key'] = 'tg.external_key';
        }

        if ($this->hasInclude($includes, 'parent-taxa')) {
            foreach ($this->dynamicRankAliases() as $alias) {
                $joinAlias = $this->parentTaxaJoinAlias($alias);
                $fields[$alias . '__scientific_name'] = "{$joinAlias}.scientific_name";
                $fields[$alias . '__vernacular_name'] = "{$joinAlias}.vernacular_name";
            }
        }

        return $fields;
    }

    /**
     * Allow helper region filter while keeping the exposed field expression simple.
     *
     * @param array<string, bool> $includes
     * @return array<string, string>
     */
    protected function allowedFilters(array $includes = []): array
    {
        $filters = parent::allowedFilters($includes);
        $filters['higher_geography_identifier'] = '__higher_geography_identifier__';

        return $filters;
    }

    /**
     * Apply parsed filters with special handling for region helper filters.
     *
     * @param array<int, array<string, mixed>> $filters
     */
    protected function applyFilters(BaseBuilder $builder, array $filters): void
    {
        $regular = [];

        foreach ($filters as $filter) {
            if (($filter['column'] ?? null) !== '__higher_geography_identifier__') {
                $regular[] = $filter;
                continue;
            }

            $this->applyHigherGeographyIdentifierFilter($builder, $filter);
        }

        parent::applyFilters($builder, $regular);
    }

    /**
     * Builds the base query used for the API.
     *
     * @return object
     *   The query builder instance.
     */
    protected function getBuilder(object $db, array $includes = []): BaseBuilder
    {
        $builder = $db->table('occurrences o')
                        ->select($this->getFieldSql($includes), false)
            ->join('taxa t', 't.id = o.taxon_id AND t.deleted_at IS NULL AND t.blocked = 0', 'left')
            ->where('o.deleted_at IS NULL', null, false)
            ->where('o.blocked = 0', null, false);

        if ($this->hasInclude($includes, 'data-source')) {
            $builder->join('data_sources ds', 'ds.id = o.data_source_id AND ds.deleted_at IS NULL', 'left');
        }

        if ($this->hasInclude($includes, 'grid-square-stats')) {
            $builder->join('grid_square_stats gss', 'gss.square = o.grid_ref_2km', 'left');
        }

        if ($this->hasInclude($includes, 'parent-taxa')) {
            foreach ($this->dynamicRankAliases() as $alias) {
                $joinAlias = $this->parentTaxaJoinAlias($alias);
                $builder->join("taxa {$joinAlias}", "{$joinAlias}.id = t.{$alias}_id", 'left');
            }
        }

        if ($this->hasInclude($includes, 'taxon-name')) {
            $builder->join('taxon_names tn', 'tn.id = o.taxon_name_id AND tn.deleted_at IS NULL', 'left');
        }

        if ($this->hasInclude($includes, 'taxon-rank')) {
            $builder->join('taxon_ranks tr', 'tr.id = t.taxon_rank_id', 'left');
        }

        if ($this->hasInclude($includes, 'taxon-group')) {
            $builder->join('taxon_groups tg', 'tg.id = t.taxon_group_id', 'left');
        }

        return $builder;
    }

    /**
     * Name of the column for looking up individual items.
     *
     * @return string
     */
    protected function getDefaultKeyColumn(): string
    {
        return 'unique_key';
    }

    /**
     * Name of the column for sorting if not otherwise specified.
     *
     * @return string
     */
    protected function getDefaultSortColumn(): string
    {
        return 'from_date';
    }

    /**
     * Parse include list for the occurrences endpoint.
     *
     * @return array<string, bool>|ResponseInterface
     */
    private function parseRequestedIncludes(): array|ResponseInterface
    {
        $raw = (string) ($this->request->getGet('include') ?? '');

        if (trim($raw) === '') {
            return [];
        }

        $parts = array_filter(array_map('trim', explode(',', strtolower($raw))), static fn (string $item): bool => $item !== '');
        $supported = $this->getAllowedIncludes($parts);
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
     * Add nested geographic region data for each occurrence.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function hydrateGeographicRegions(array &$rows): void
    {
        $occurrenceIds = array_values(array_filter(array_map(static fn (array $row): int => (int) ($row['__occurrence_id'] ?? 0), $rows)));

        if ($occurrenceIds === []) {
            foreach ($rows as &$row) {
                $row['geographic_regions'] = [];
            }

            return;
        }

        $db = db_connect();
        $regionRows = $db->table('geographic_regions_occurrences gro')
            ->select('gro.occurrence_id, gr.higher_geography_identifier, gr.higher_geography, gr.location_type')
            ->join('geographic_regions gr', 'gr.id = gro.geographic_region_id AND gr.deleted_at IS NULL')
            ->whereIn('gro.occurrence_id', $occurrenceIds)
            ->orderBy('gro.occurrence_id', 'ASC')
            ->orderBy('gr.higher_geography_identifier', 'ASC')
            ->get()
            ->getResultArray();

        $regionsByOccurrence = [];

        foreach ($regionRows as $regionRow) {
            $occurrenceId = (int) $regionRow['occurrence_id'];
            $regionsByOccurrence[$occurrenceId][] = [
                'higher_geography_identifier' => (int) $regionRow['higher_geography_identifier'],
                'higher_geography' => $regionRow['higher_geography'],
                'location_type' => $regionRow['location_type'],
            ];
        }

        foreach ($rows as &$row) {
            $occurrenceId = (int) ($row['__occurrence_id'] ?? 0);
            $row['geographic_regions'] = $regionsByOccurrence[$occurrenceId] ?? [];
        }
    }

    /**
     * Remove helper-only fields from the response payload.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function removeInternalFields(array &$rows): void
    {
        foreach ($rows as &$row) {
            unset($row['__occurrence_id']);
        }
    }

    /**
     * Apply filter condition for higher_geography_identifier through the join table.
     *
     * @param array<string, mixed> $filter
     */
    private function applyHigherGeographyIdentifierFilter(BaseBuilder $builder, array $filter): void
    {
        $operator = (string) ($filter['operator'] ?? 'eq');
        $rawValue = $filter['value'] ?? null;
        $db = db_connect();
        $groTable = $db->prefixTable('geographic_regions_occurrences');
        $grTable = $db->prefixTable('geographic_regions');

        $subqueryBase = 'EXISTS (SELECT 1 FROM ' . $groTable . ' gro '
            . 'INNER JOIN ' . $grTable . ' gr ON gr.id = gro.geographic_region_id '
            . 'AND gr.deleted_at IS NULL WHERE gro.occurrence_id = o.id AND ';

        if ($operator === 'in') {
            $values = is_array($rawValue)
                ? $rawValue
                : array_filter(array_map('trim', explode(',', (string) $rawValue)), static fn (string $v): bool => $v !== '');

            $escapedValues = array_map(static fn ($v) => $db->escape($v), $values === [] ? [''] : $values);
            $builder->where(new RawSql($subqueryBase . 'gr.higher_geography_identifier IN (' . implode(',', $escapedValues) . '))'));

            return;
        }

        $operatorSql = match ($operator) {
            'gte' => ' >= ',
            'lte' => ' <= ',
            default => ' = ',
        };

        $builder->where(new RawSql($subqueryBase . 'gr.higher_geography_identifier' . $operatorSql . $db->escape($rawValue) . ')'));
    }

    /**
     * SQL expression for helper higher geography identifier field.
     */
    private function buildHigherGeographyIdentifierSql(): string
    {
        $db = db_connect();
        $groTable = $db->prefixTable('geographic_regions_occurrences');
        $grTable = $db->prefixTable('geographic_regions');

        return '(SELECT MIN(gr.higher_geography_identifier) '
            . 'FROM ' . $groTable . ' gro '
            . 'INNER JOIN ' . $grTable . ' gr ON gr.id = gro.geographic_region_id '
            . 'WHERE gro.occurrence_id = o.id AND gr.deleted_at IS NULL)';
    }
}
