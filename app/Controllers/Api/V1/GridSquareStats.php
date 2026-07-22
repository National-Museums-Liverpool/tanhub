<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;

/**
 * API endpoints for grid square stats.
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
