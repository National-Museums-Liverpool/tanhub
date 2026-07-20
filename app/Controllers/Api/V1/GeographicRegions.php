<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * API endpoints for geographic regions.
 */
class GeographicRegions extends ApiController
{
    /**
     * List geographic regions.
     */
    public function index(): ResponseInterface
    {
        $pagination = $this->getPagination();

        if ($pagination instanceof ResponseInterface) {
            return $pagination;
        }

        $sorts = $this->getSorts([
            'higher_geography_identifier' => 'higher_geography_identifier',
            'higher_geography' => 'higher_geography',
            'location_type' => 'location_type',
            'data_source_abbr' => 'data_source_abbr',
        ], 'higher_geography');

        if ($sorts instanceof ResponseInterface) {
            return $sorts;
        }

        $filters = $this->getFilters([
            'higher_geography_identifier' => 'higher_geography_identifier',
            'higher_geography' => 'higher_geography',
            'location_type' => 'location_type',
            'data_source_abbr' => '__data_source_abbr__',
        ]);

        if ($filters instanceof ResponseInterface) {
            return $filters;
        }

        $normalFilters = [];
        $customFilters = [];

        foreach ($filters as $filter) {
            if (($filter['column'] ?? null) === '__data_source_abbr__') {
                $customFilters[] = $filter;
                continue;
            }

            $normalFilters[] = $filter;
        }

        $builder = db_connect()->table('geographic_regions')
            ->select('CAST(higher_geography_identifier AS INTEGER) AS higher_geography_identifier, higher_geography, location_type, data_sources.abbr AS data_source_abbr')
            ->join('data_sources', 'data_sources.id = geographic_regions.data_source_id', 'left')
            ->where('geographic_regions.deleted_at', null);

        $this->applyFilters($builder, $normalFilters);
        $this->applyDataSourceAbbrFilter($builder, $customFilters);
        $this->applySorts($builder, $sorts);

        $total = (clone $builder)->countAllResults();

        $data = $builder
            ->limit($pagination['limit'], $pagination['offset'])
            ->get()
            ->getResultArray();

        return $this->respondList($data, $total, $pagination['limit'], $pagination['offset']);
    }

    /**
     * Return a single geographic region by higher geography identifier.
     */
    public function show(string $higherGeographyIdentifier): ResponseInterface
    {
        $item = db_connect()->table('geographic_regions')
            ->select('CAST(higher_geography_identifier AS INTEGER) AS higher_geography_identifier, higher_geography, location_type, data_sources.abbr AS data_source_abbr')
            ->join('data_sources', 'data_sources.id = geographic_regions.data_source_id', 'left')
            ->where('higher_geography_identifier', $higherGeographyIdentifier)
            ->where('geographic_regions.deleted_at', null)
            ->get()
            ->getRowArray();

        if ($item === null) {
            return $this->respondProblem(404, 'Resource not found', "No geographic region exists for higher_geography_identifier '{$higherGeographyIdentifier}'.");
        }

        return $this->respondItem($item);
    }

    /**
     * Apply the joined data source abbreviation filter.
     *
     * @param object $builder
     * @param array<int, array<string, mixed>> $filters
     * @return void
     */
    private function applyDataSourceAbbrFilter(object $builder, array $filters): void
    {
        foreach ($filters as $filter) {
            if (($filter['column'] ?? null) !== '__data_source_abbr__') {
                continue;
            }

            if (($filter['operator'] ?? null) !== 'eq') {
                continue;
            }

            $builder->where('data_sources.abbr', $filter['value']);
        }
    }
}
