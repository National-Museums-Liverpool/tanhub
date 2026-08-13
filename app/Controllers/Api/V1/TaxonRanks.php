<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\RawSql;

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
        $builder = $db->table('taxon_ranks')
            ->select($this->getFieldSql($includes), false)
            ->where('deleted_at', null);

        if ($this->isReportingOnly() !== null) {
            $this->applyReportingOnly($builder, $this->isReportingOnly());
        }

        return $builder;
    }

    /**
     * Indicate that taxon ranks can be restricted to reporting ranks.
     *
     * @return bool Always true for this resource.
     */
    protected function supportsReportingOnly(): bool
    {
        return true;
    }

    /**
     * Apply the reporting-rank predicate to the taxon-ranks query.
     *
     * @param BaseBuilder $builder Query builder to modify.
     * @param bool        $reportingOnly Whether only reporting ranks should remain.
     */
    protected function applyReportingOnly(BaseBuilder $builder, bool $reportingOnly): void
    {
        if ($reportingOnly) {
            $builder->where(new RawSql('is_reporting = 1'));
        }
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
