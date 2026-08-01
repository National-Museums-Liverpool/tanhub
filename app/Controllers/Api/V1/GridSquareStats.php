<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;

/**
 * API endpoints for the `grid_square_stats` resource.
 *
 * Serves `GET api/v1/grid-square-stats` (list) and `GET api/v1/grid-square-stats/{uuid}`
 * (show); see {@see ApiResourceController} for the shared pagination/sort/filter behavior
 * and {@see ApiController} for the public-read/rate-limit model that applies to all
 * endpoints in this namespace. Each row is always left-joined to its
 * {@see GeographicRegions} row (for the `higher_geography_identifier` key column), with
 * additional descriptive region fields exposed via an optional `?include=geographic-region`.
 */
class GridSquareStats extends ApiResourceController
{
    /**
     * Retrieve list of resources that can be included (joined) in requests.
     *
     * @return string[]
     *   Resource name list.
     */
    protected function getAllowedIncludes(array $requested): array
    {
        return ['geographic-region'];
    }

    /**
     * Retrieve included fields array.
     *
     * @return array<string, string>
     *   Array of field identifiers and their corresponding query columns.
     */
    protected function getAllowedFields(array $includes = []): array
    {
        $fields = [
            'uuid' => 'gs.uuid',
            'square' => 'gs.square',
            'easting' => 'gs.easting',
            'northing' => 'gs.northing',
            'lon' => 'gs.lon',
            'lat' => 'gs.lat',
            'partial' => 'gs.partial',
            'occurrences_count' => 'gs.occurrences_count',
            'species_count' => 'gs.species_count',
            'rarity_score' => 'gs.rarity_score',
            'higher_geography_identifier' => 'gr.higher_geography_identifier',
        ];

        if ($this->hasInclude($includes, 'geographic-region')) {
            $fields['geographic_region__higher_geography'] = 'gr.higher_geography';
            $fields['geographic_region__location_type'] = 'gr.location_type';
        }

        return $fields;
    }

    /**
     * Builds the base query used for the API.
     *
     * Always left-joins `geographic_regions` (soft-deleted regions excluded) so the
     * `higher_geography_identifier` key column is available regardless of includes;
     * additional region fields are only selected when `?include=geographic-region` is set.
     *
     * @return object
     *   The query builder instance.
     */
    protected function getBuilder(object $db, array $includes = []): BaseBuilder
    {
        $builder = $db->table('grid_square_stats gs')
            ->select($this->getFieldSql($includes), false)
            ->join('geographic_regions gr', 'gr.id = gs.geographic_region_id AND gr.deleted_at IS NULL', 'left');

        return $builder;
    }

    /**
     * Name of the column for looking up individual items.
     *
     * @return string
     */
    protected function getDefaultKeyColumn(): string
    {
        return 'uuid';
    }

    /**
     * Name of the column for sorting if not otherwise specified.
     *
     * @return string
     */
    protected function getDefaultSortColumn(): string
    {
        return 'square';
    }
}
