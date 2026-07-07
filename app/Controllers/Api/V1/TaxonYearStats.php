<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * API endpoints for taxon year stats.
 */
class TaxonYearStats extends ApiController
{
    public function index(): ResponseInterface
    {
        $db = db_connect();
        $prefix = $db->getPrefix();

        $pagination = $this->getPagination();
        if ($pagination instanceof ResponseInterface) {
            return $pagination;
        }

        $sorts = $this->getSorts([
            'uuid' => 'uuid',
            'year' => 'year',
            'occurrences_count' => 'occurrences_count',
            'grid_square_count' => 'grid_square_count',
        ], 'year');
        if ($sorts instanceof ResponseInterface) {
            return $sorts;
        }

        $filters = $this->getFilters([
            'uuid' => 'uuid',
            'taxon_identifier' => '__taxon_identifier__',
            'geographic_region_identifier' => '__geographic_region_identifier__',
            'year' => 'year',
            'occurrences_count' => 'occurrences_count',
            'grid_square_count' => 'grid_square_count',
        ]);
        if ($filters instanceof ResponseInterface) {
            return $filters;
        }

        $builder = $db->table('taxon_year_stats')
            ->select('uuid, (SELECT taxon_identifier FROM ' . $prefix . 'taxa WHERE id = taxon_id AND deleted_at IS NULL AND blocked = 0) AS taxon_identifier, (SELECT higher_geography_identifier FROM ' . $prefix . 'geographic_regions WHERE id = geographic_region_id AND deleted_at IS NULL) AS geographic_region_identifier, year, occurrences_count, grid_square_count', false)
            ->where('taxon_id IN (SELECT id FROM ' . $prefix . 'taxa WHERE deleted_at IS NULL AND blocked = 0)', null, false);

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

        $item = $db->table('taxon_year_stats')
            ->select('uuid, (SELECT taxon_identifier FROM ' . $prefix . 'taxa WHERE id = taxon_id AND deleted_at IS NULL AND blocked = 0) AS taxon_identifier, (SELECT higher_geography_identifier FROM ' . $prefix . 'geographic_regions WHERE id = geographic_region_id AND deleted_at IS NULL) AS geographic_region_identifier, year, occurrences_count, grid_square_count', false)
            ->where('uuid', $uuid)
            ->where('taxon_id IN (SELECT id FROM ' . $prefix . 'taxa WHERE deleted_at IS NULL AND blocked = 0)', null, false)
            ->get()
            ->getRowArray();

        if ($item === null) {
            return $this->respondProblem(404, 'Resource not found', "No taxon year stats row exists for uuid '{$uuid}'.");
        }

        return $this->respondItem($item);
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
