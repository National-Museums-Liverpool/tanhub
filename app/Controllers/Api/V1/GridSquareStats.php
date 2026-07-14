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
        $includes = $this->getIncludes();

        if ($includes instanceof ResponseInterface) {
            return $includes;
        }

        $pagination = $this->getPagination();
        if ($pagination instanceof ResponseInterface) {
            return $pagination;
        }

        $sorts = $this->getSorts($this->allowedSorts($includes), 'square');
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
            $geographicRegionColumn = 'geographic_region_id';

            if ($operator === 'eq') {
                $builder->where($geographicRegionColumn . ' IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE higher_geography_identifier = ' . $db->escape($value) . ' AND deleted_at IS NULL)', null, false);
            } elseif ($operator === 'in') {
                $values = is_array($value) ? $value : array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $v): bool => $v !== '');
                $escaped = array_map(static fn ($v): string => $db->escape((string) $v), $values);
                if ($escaped !== []) {
                    $builder->where($geographicRegionColumn . ' IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE higher_geography_identifier IN (' . implode(',', $escaped) . ') AND deleted_at IS NULL)', null, false);
                }
            } elseif ($operator === 'contains') {
                $like = '%' . $db->escapeLikeString(strtolower((string) $value)) . '%';
                $builder->where($geographicRegionColumn . ' IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE LOWER(CAST(higher_geography_identifier AS TEXT)) LIKE ' . $db->escape($like) . " ESCAPE '!' AND deleted_at IS NULL)", null, false);
            } elseif ($operator === 'gte') {
                $builder->where($geographicRegionColumn . ' IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE higher_geography_identifier >= ' . $db->escape($value) . ' AND deleted_at IS NULL)', null, false);
            } elseif ($operator === 'lte') {
                $builder->where($geographicRegionColumn . ' IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE higher_geography_identifier <= ' . $db->escape($value) . ' AND deleted_at IS NULL)', null, false);
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
            return $this->respondProblem(404, 'Resource not found', "No grid square stats row exists for uuid '{$uuid}'.");
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
            'square' => 'square',
            'easting' => 'easting',
            'northing' => 'northing',
            'partial' => 'partial',
            'occurrences_count' => 'occurrences_count',
            'species_count' => 'species_count',
            'geographic_region_identifier' => 'geographic_region_identifier',
        ];

        if (! $this->hasInclude($includes, 'geographic_region')) {
            return $sorts;
        }

        $sorts['geographic_region'] = 'higher_geography';
        $sorts['geographic_region_location_type'] = 'location_type';

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
            'square' => 'square',
            'geographic_region_identifier' => '__geographic_region_identifier__',
            'easting' => 'easting',
            'northing' => 'northing',
            'partial' => 'partial',
            'occurrences_count' => 'occurrences_count',
            'species_count' => 'species_count',
        ];

        if (! $this->hasInclude($includes, 'geographic_region')) {
            return $filters;
        }

        $filters['geographic_region'] = 'higher_geography';
        $filters['geographic_region_location_type'] = 'location_type';

        return $filters;
    }

    private function buildDefaultBuilder($db, string $prefix)
    {
        return $db->table('grid_square_stats')
            ->select('uuid, square, (SELECT higher_geography_identifier FROM ' . $prefix . 'geographic_regions WHERE id = geographic_region_id AND deleted_at IS NULL) AS geographic_region_identifier, easting, northing, partial, occurrences_count, species_count', false);
    }

    /**
     * @param array<string, bool> $includes
     */
    private function buildIncludedBuilder($db, string $prefix, array $includes)
    {
        $builder = $db->table('grid_square_stats')
            ->select('uuid, square, (SELECT higher_geography_identifier FROM ' . $prefix . 'geographic_regions WHERE id = geographic_region_id AND deleted_at IS NULL) AS geographic_region_identifier, easting, northing, partial, occurrences_count, species_count', false);

        if ($this->hasInclude($includes, 'geographic_region')) {
            $builder->select('(SELECT higher_geography FROM ' . $prefix . 'geographic_regions WHERE id = geographic_region_id AND deleted_at IS NULL) AS geographic_region', false);
            $builder->select('(SELECT location_type FROM ' . $prefix . 'geographic_regions WHERE id = geographic_region_id AND deleted_at IS NULL) AS geographic_region_location_type', false);
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
        $supported = ['geographic_region'];
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
}
