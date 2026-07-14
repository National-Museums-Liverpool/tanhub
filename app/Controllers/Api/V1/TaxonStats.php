<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * API endpoints for taxon stats.
 */
class TaxonStats extends ApiController
{
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

        $sorts = $this->getSorts($this->allowedSorts($includes), 'last_record_date');
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

        $normal = [];
        $custom = [];
        foreach ($filters as $filter) {
            if (str_starts_with((string) $filter['column'], '__')) {
                $custom[] = $filter;
                continue;
            }
            $normal[] = $filter;
        }
        $this->applyFilters($builder, $normal);

        foreach ($custom as $filter) {
            $column = (string) $filter['column'];
            $operator = (string) $filter['operator'];
            $value = $filter['value'];

            if ($column === '__taxon_identifier__') {
                $this->applyIdentifierFilter($builder, $operator, $value, 'taxon_id', $prefix . 'taxa', 'taxon_identifier', $db, ' AND deleted_at IS NULL AND blocked = 0');
                continue;
            }

            if ($column === '__geographic_region_identifier__') {
                $this->applyIdentifierFilter($builder, $operator, $value, 'geographic_region_id', $prefix . 'geographic_regions', 'higher_geography_identifier', $db, ' AND deleted_at IS NULL');
            }
        }

        $this->applySorts($builder, $sorts);

        $total = (clone $builder)->countAllResults();
        $data = $builder->limit($pagination['limit'], $pagination['offset'])->get()->getResultArray();

        return $this->respondList($data, $total, $pagination['limit'], $pagination['offset']);
    }

    public function show(string $uuid): ResponseInterface
    {
        $db = db_connect();
        $prefix = $db->getPrefix();
        $includes = $this->getIncludes();

        if ($includes instanceof ResponseInterface) {
            return $includes;
        }

        $usesIncludedBuilder = $this->usesIncludedBuilder($includes);

        $item = ($usesIncludedBuilder
            ? $this->buildIncludedBuilder($db, $prefix, $includes)
            : $this->buildDefaultBuilder($db, $prefix))
            ->where('uuid', $uuid)
            ->get()
            ->getRowArray();

        if ($item === null) {
            return $this->respondProblem(404, 'Resource not found', "No taxon stats row exists for uuid '{$uuid}'.");
        }

        return $this->respondItem($item);
    }

    /**
     * @param array<string, bool> $includes
     * @return array<string, string>
     */
    private function allowedSorts(array $includes): array
    {
        $usesIncludedBuilder = $this->usesIncludedBuilder($includes);

        $sorts = [
            'uuid' => 'uuid',
            'occurrences_count' => 'occurrences_count',
            'grid_square_count' => 'grid_square_count',
            'first_record_date' => 'first_record_date',
            'last_record_date' => 'last_record_date',
            'first_recorder' => 'first_recorder',
            'last_recorder' => 'last_recorder',
            'first_verified_record_date' => 'first_verified_record_date',
            'last_verified_record_date' => 'last_verified_record_date',
            'first_verified_recorder' => 'first_verified_recorder',
            'last_verified_recorder' => 'last_verified_recorder',
            'taxon_identifier' => 'taxon_identifier',
            'geographic_region_identifier' => 'geographic_region_identifier',
        ];

        if ($this->hasInclude($includes, 'taxon')) {
            $sorts['taxon_scientific_name'] = 'taxon_scientific_name';
            $sorts['taxon_vernacular_name'] = 'taxon_vernacular_name';
        }

        if ($this->hasInclude($includes, 'geographic_region')) {
            $sorts['geographic_region'] = 'geographic_region';
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
            'uuid' => 'uuid',
            'taxon_identifier' => '__taxon_identifier__',
            'geographic_region_identifier' => '__geographic_region_identifier__',
            'occurrences_count' => 'occurrences_count',
            'grid_square_count' => 'grid_square_count',
            'first_record_date' => 'first_record_date',
            'last_record_date' => 'last_record_date',
            'first_recorder' => 'first_recorder',
            'last_recorder' => 'last_recorder',
            'first_verified_record_date' => 'first_verified_record_date',
            'last_verified_record_date' => 'last_verified_record_date',
            'first_verified_recorder' => 'first_verified_recorder',
            'last_verified_recorder' => 'last_verified_recorder',
        ];

        if ($this->hasInclude($includes, 'taxon')) {
            $filters['taxon_scientific_name'] = 'taxon_scientific_name';
            $filters['taxon_vernacular_name'] = 'taxon_vernacular_name';
        }

        if ($this->hasInclude($includes, 'geographic_region')) {
            $filters['geographic_region'] = 'geographic_region';
        }

        return $filters;
    }

    private function buildDefaultBuilder($db, string $prefix)
    {
        return $db->table('taxon_stats')
            ->select('uuid, (SELECT taxon_identifier FROM ' . $prefix . 'taxa WHERE id = taxon_id AND deleted_at IS NULL AND blocked = 0) AS taxon_identifier, (SELECT higher_geography_identifier FROM ' . $prefix . 'geographic_regions WHERE id = geographic_region_id AND deleted_at IS NULL) AS geographic_region_identifier, occurrences_count, grid_square_count, first_record_date, last_record_date, first_recorder, last_recorder, first_verified_record_date, last_verified_record_date, first_verified_recorder, last_verified_recorder', false)
            ->where('taxon_id IN (SELECT id FROM ' . $prefix . 'taxa WHERE deleted_at IS NULL AND blocked = 0)', null, false);
    }

    /**
     * @param array<string, bool> $includes
     */
    private function buildIncludedBuilder($db, string $prefix, array $includes)
    {
        $builder = $db->table('taxon_stats')
            ->select('uuid, occurrences_count, grid_square_count, first_record_date, last_record_date, first_recorder, last_recorder, first_verified_record_date, last_verified_record_date, first_verified_recorder, last_verified_recorder', false)
            ->where('taxon_id IN (SELECT id FROM ' . $prefix . 'taxa WHERE deleted_at IS NULL AND blocked = 0)', null, false);

        if ($this->hasInclude($includes, 'taxon')) {
            $builder->select('(SELECT taxon_identifier FROM ' . $prefix . 'taxa WHERE id = taxon_id AND deleted_at IS NULL AND blocked = 0) AS taxon_identifier', false);
            $builder->select('(SELECT scientific_name FROM ' . $prefix . 'taxa WHERE id = taxon_id AND deleted_at IS NULL AND blocked = 0) AS taxon_scientific_name', false);
            $builder->select('(SELECT vernacular_name FROM ' . $prefix . 'taxa WHERE id = taxon_id AND deleted_at IS NULL AND blocked = 0) AS taxon_vernacular_name', false);
        } else {
            $builder->select('(SELECT taxon_identifier FROM ' . $prefix . 'taxa WHERE id = taxon_id AND deleted_at IS NULL AND blocked = 0) AS taxon_identifier', false);
        }

        if ($this->hasInclude($includes, 'geographic_region')) {
            $builder->select('(SELECT higher_geography_identifier FROM ' . $prefix . 'geographic_regions WHERE id = geographic_region_id AND deleted_at IS NULL) AS geographic_region_identifier', false);
            $builder->select('(SELECT higher_geography FROM ' . $prefix . 'geographic_regions WHERE id = geographic_region_id AND deleted_at IS NULL) AS geographic_region', false);
        } else {
            $builder->select('(SELECT higher_geography_identifier FROM ' . $prefix . 'geographic_regions WHERE id = geographic_region_id AND deleted_at IS NULL) AS geographic_region_identifier', false);
        }

        return $builder;
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
        $supported = ['taxon', 'geographic_region'];
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

    private function applyIdentifierFilter($builder, string $operator, $value, string $localColumn, string $relatedTable, string $relatedField, $db, string $extraWhere = ''): void
    {
        if ($operator === 'eq') {
            $builder->where($localColumn . ' IN (SELECT id FROM ' . $relatedTable . ' WHERE ' . $relatedField . ' = ' . $db->escape($value) . $extraWhere . ')', null, false);
            return;
        }

        if ($operator === 'in') {
            $values = is_array($value) ? $value : array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $v): bool => $v !== '');
            $escaped = array_map(static fn ($v): string => $db->escape((string) $v), $values);
            if ($escaped !== []) {
                $builder->where($localColumn . ' IN (SELECT id FROM ' . $relatedTable . ' WHERE ' . $relatedField . ' IN (' . implode(',', $escaped) . ')' . $extraWhere . ')', null, false);
            }
            return;
        }

        if ($operator === 'contains') {
            $like = '%' . $db->escapeLikeString(strtolower((string) $value)) . '%';
            $builder->where($localColumn . ' IN (SELECT id FROM ' . $relatedTable . ' WHERE LOWER(CAST(' . $relatedField . ' AS TEXT)) LIKE ' . $db->escape($like) . " ESCAPE '!'" . $extraWhere . ')', null, false);
            return;
        }

        if ($operator === 'gte') {
            $builder->where($localColumn . ' IN (SELECT id FROM ' . $relatedTable . ' WHERE ' . $relatedField . ' >= ' . $db->escape($value) . $extraWhere . ')', null, false);
            return;
        }

        if ($operator === 'lte') {
            $builder->where($localColumn . ' IN (SELECT id FROM ' . $relatedTable . ' WHERE ' . $relatedField . ' <= ' . $db->escape($value) . $extraWhere . ')', null, false);
        }
    }
}
