<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;

/**
 * API endpoints for the `taxon_groups` lookup resource.
 *
 * Serves `GET api/v1/taxon-groups` (list) and `GET api/v1/taxon-groups/{external_key}` (show);
 * see {@see ApiResourceController} for the shared pagination/sort/filter behavior and
 * {@see ApiController} for the public-read/rate-limit model that applies to all endpoints in
 * this namespace. Soft-deleted rows (`deleted_at IS NOT NULL`) are excluded from all queries.
 * No `?include=` expansions are supported; other resources (e.g. {@see Taxa}, {@see Occurrences})
 * join into this table via their own `taxon-group` include.
 */
class TaxonGroups extends ApiResourceController
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
            'external_key' => 'external_key',
            'title' => 'title',
            'friendly' => 'friendly',
            'indicia_taxon_group_id' => 'indicia_taxon_group_id',
            'implied' => 'implied',
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
        return $db->table('taxon_groups')
            ->select($this->getFieldSql($includes), false)
            ->where('deleted_at', null);
    }

    /**
     * Name of the column for looking up individual items.
     *
     * @return string
     */
    protected function getDefaultKeyColumn(): string
    {
        return 'external_key';
    }

    /**
     * Name of the column for sorting if not otherwise specified.
     *
     * @return string
     */
    protected function getDefaultSortColumn(): string
    {
        return 'title';
    }
}
