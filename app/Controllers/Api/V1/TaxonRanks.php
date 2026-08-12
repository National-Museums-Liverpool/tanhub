<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;

/**
 * API endpoints for the `taxon_ranks` lookup resource.
 *
 * Serves `GET api/v1/taxon-ranks` (list) and `GET api/v1/taxon-ranks/{abbr}` (show); see
 * {@see ApiResourceController} for the shared pagination/sort/filter behavior and
 * {@see ApiController} for the public-read/rate-limit model that applies to all endpoints in
 * this namespace. Soft-deleted rows (`deleted_at IS NOT NULL`) are excluded from all queries.
 * No `?include=` expansions are supported; other resources (e.g. {@see Taxa}, {@see Occurrences})
 * join into this table via their own `taxon-rank` include.
 */
class TaxonRanks extends ApiResourceController
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
            'rank' => 'rank',
            'sort_order' => 'sort_order',
            'is_reporting' => 'is_reporting',
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
        return $db->table('taxon_ranks')
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
        return 'abbr';
    }

    /**
     * Name of the column for sorting if not otherwise specified.
     *
     * @return string
     */
    protected function getDefaultSortColumn(): string
    {
        return 'sort_order';
    }
}
