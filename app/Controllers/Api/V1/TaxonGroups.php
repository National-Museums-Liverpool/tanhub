<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;

/**
 * API endpoints for taxon groups.
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
