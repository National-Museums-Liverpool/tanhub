<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;

/**
 * API endpoints for the `data_sources` lookup resource.
 *
 * Serves `GET api/v1/data-sources` (list) and `GET api/v1/data-sources/{abbr}` (show); see
 * {@see ApiResourceController} for the shared pagination/sort/filter behavior and
 * {@see ApiController} for the public-read/rate-limit model that applies to all endpoints
 * in this namespace. All three fields ({@see self::getAllowedFields()}) are filterable and
 * sortable; no `?include=` expansions are supported.
 */
class DataSources extends ApiResourceController
{
    /**
     * Retrieve included fields array.
     *
     * @return array<string, string>
     *   Array of field identifiers and their corresponding query columns.
     */
    protected function getAllowedFields(array $includes = []): array
    {
        return [
            'abbr' => 'abbr',
            'title' => 'title',
            'url' => 'url',
        ];
    }

    /**
     * Builds the base query used for the API.
     *
     * @return object
     *   The query builder instance.
     */
    protected function getBuilder(object $db, array $includes = []): BaseBuilder
    {
        return $db->table('data_sources')
            ->select($this->getFieldSql($includes), false);
    }

    /**
     * Name of the column for looking up individual items.
     *
     * @return string
     */
    protected function getDefaultKeyColumn(): string
    {
        return 'abbr';
    }

    /**
     * Name of the column for sorting if not otherwise specified.
     *
     * @return string
     */
    protected function getDefaultSortColumn(): string
    {
        return 'abbr';
    }
}
