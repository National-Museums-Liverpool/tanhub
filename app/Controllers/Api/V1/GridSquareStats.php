<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * API endpoints for grid square stats.
 */
class GridSquareStats extends ApiController
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
            'square' => 'square',
            'easting' => 'easting',
            'northing' => 'northing',
            'partial' => 'partial',
            'occurrences_count' => 'occurrences_count',
            'species_count' => 'species_count',
        ], 'square');
        if ($sorts instanceof ResponseInterface) {
            return $sorts;
        }

        $filters = $this->getFilters([
            'uuid' => 'uuid',
            'square' => 'square',
            'geographic_region_identifier' => '__geographic_region_identifier__',
            'easting' => 'easting',
            'northing' => 'northing',
            'partial' => 'partial',
            'occurrences_count' => 'occurrences_count',
            'species_count' => 'species_count',
        ]);
        if ($filters instanceof ResponseInterface) {
            return $filters;
        }

        $builder = $db->table('grid_square_stats')
            ->select('uuid, square, (SELECT higher_geography_identifier FROM ' . $prefix . 'geographic_regions WHERE id = geographic_region_id AND deleted_at IS NULL) AS geographic_region_identifier, easting, northing, partial, occurrences_count, species_count', false);

        $normal = [];
        $custom = [];
        foreach ($filters as $filter) {
            if ($filter['column'] === '__geographic_region_identifier__') {
                $custom[] = $filter;
                continue;
            }
            $normal[] = $filter;
        }

        $this->applyFilters($builder, $normal);

        foreach ($custom as $filter) {
            $operator = (string) $filter['operator'];
            $value = $filter['value'];

            if ($operator === 'eq') {
                $builder->where('geographic_region_id IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE higher_geography_identifier = ' . $db->escape($value) . ' AND deleted_at IS NULL)', null, false);
            } elseif ($operator === 'in') {
                $values = is_array($value) ? $value : array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $v): bool => $v !== '');
                $escaped = array_map(static fn ($v): string => $db->escape((string) $v), $values);
                if ($escaped !== []) {
                    $builder->where('geographic_region_id IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE higher_geography_identifier IN (' . implode(',', $escaped) . ') AND deleted_at IS NULL)', null, false);
                }
            } elseif ($operator === 'contains') {
                $like = '%' . $db->escapeLikeString(strtolower((string) $value)) . '%';
                $builder->where('geographic_region_id IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE LOWER(CAST(higher_geography_identifier AS TEXT)) LIKE ' . $db->escape($like) . " ESCAPE '!' AND deleted_at IS NULL)", null, false);
            } elseif ($operator === 'gte') {
                $builder->where('geographic_region_id IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE higher_geography_identifier >= ' . $db->escape($value) . ' AND deleted_at IS NULL)', null, false);
            } elseif ($operator === 'lte') {
                $builder->where('geographic_region_id IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE higher_geography_identifier <= ' . $db->escape($value) . ' AND deleted_at IS NULL)', null, false);
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

        $item = $db->table('grid_square_stats')
            ->select('uuid, square, (SELECT higher_geography_identifier FROM ' . $prefix . 'geographic_regions WHERE id = geographic_region_id AND deleted_at IS NULL) AS geographic_region_identifier, easting, northing, partial, occurrences_count, species_count', false)
            ->where('uuid', $uuid)
            ->get()
            ->getRowArray();

        if ($item === null) {
            return $this->respondProblem(404, 'Resource not found', "No grid square stats row exists for uuid '{$uuid}'.");
        }

        return $this->respondItem($item);
    }
}
