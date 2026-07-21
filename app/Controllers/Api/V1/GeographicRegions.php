<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;

/**
 * API endpoints for geographic regions.
 */
class GeographicRegions extends ApiResourceController
{

    /**
     * Retrieve list of resources that can be included (joined) in requests.
     *
     * @return string[]
     *   Resource name list.
     */
    protected function getAllowedIncludes(): array
    {
        return ['data-source'];
    }

    /**
     * Retrieve included fields array.
     *
     * @return array<string, string>
     *   Array of field identifiers and their corresponding query columns.
     */
    protected function allowedFields(array $includes = []): array
    {
        $fields = [
            'higher_geography_identifier' => 'g.higher_geography_identifier',
            'higher_geography' => 'g.higher_geography',
            'location_type' => 'g.location_type',
        ];
        if ($this->hasInclude($includes, 'data-source')) {
            $fields['data_source__abbr'] = 'ds.abbr';
            $fields['data_source__title'] = 'ds.title';
            $fields['data_source__url'] = 'ds.url';
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
        $builder =  $db->table('geographic_regions g')
            ->select($this->getFieldSql($includes))
            ->where('g.deleted_at', null);
        if ($this->hasInclude($includes, 'data-source')) {
            $builder->join('data_sources ds', 'ds.id = g.data_source_id', 'left');
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
        return 'higher_geography_identifier';
    }

    /**
     * Name of the column for sorting if not otherwise specified.
     *
     * @return string
     */
    protected function getDefaultSortColumn(): string
    {
        return 'higher_geography';
    }
}
