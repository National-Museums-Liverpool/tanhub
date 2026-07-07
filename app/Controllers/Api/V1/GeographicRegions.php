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
            'higher_geography_identifier' => 'geographic_regions.higher_geography_identifier',
            'higher_geography' => 'geographic_regions.higher_geography',
            'location_type' => 'geographic_regions.location_type',
            'data_source_abbr' => 'data_sources.abbr',
        ], 'higher_geography');

        if ($sorts instanceof ResponseInterface) {
            return $sorts;
        }

        $filters = $this->getFilters([
            'higher_geography_identifier' => 'geographic_regions.higher_geography_identifier',
            'higher_geography' => 'geographic_regions.higher_geography',
            'location_type' => 'geographic_regions.location_type',
            'data_source_abbr' => 'data_sources.abbr',
        ]);

        if ($filters instanceof ResponseInterface) {
            return $filters;
        }

        $builder = db_connect()->table('geographic_regions')
            ->select('geographic_regions.higher_geography_identifier, geographic_regions.higher_geography, geographic_regions.location_type, data_sources.abbr AS data_source_abbr')
            ->join('data_sources', 'data_sources.id = geographic_regions.data_source_id', 'left')
            ->where('geographic_regions.deleted_at', null);

        $this->applyFilters($builder, $filters);
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
            ->select('geographic_regions.higher_geography_identifier, geographic_regions.higher_geography, geographic_regions.location_type, data_sources.abbr AS data_source_abbr')
            ->join('data_sources', 'data_sources.id = geographic_regions.data_source_id', 'left')
            ->where('geographic_regions.higher_geography_identifier', $higherGeographyIdentifier)
            ->where('geographic_regions.deleted_at', null)
            ->get()
            ->getRowArray();

        if ($item === null) {
            return $this->respondProblem(404, 'Resource not found', "No geographic region exists for higher_geography_identifier '{$higherGeographyIdentifier}'.");
        }

        return $this->respondItem($item);
    }
}
