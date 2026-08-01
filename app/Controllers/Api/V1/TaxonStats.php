<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;

/**
 * API endpoints for the `taxon_stats` resource (per-taxon, per-region occurrence aggregates).
 *
 * Serves `GET api/v1/taxon-stats` (list) and `GET api/v1/taxon-stats/{uuid}` (show); see
 * {@see ApiResourceController} for the shared pagination/sort/filter behavior and
 * {@see ApiController} for the public-read/rate-limit model that applies to all endpoints
 * in this namespace. Every row is inner-joined to its owning {@see Taxa} row (blocked and
 * soft-deleted taxa excluded) and left-joined to {@see GeographicRegions} for the region key
 * column. Supports `?include=` expansions for `geographic-region`, `taxon` (base taxon
 * fields, itself gating `parent-taxa`, `taxon-media`, `taxon-group`, and `taxon-rank`).
 */
class TaxonStats extends ApiResourceController
{

    /**
     * Retrieve list of resources that can be included (joined) in requests.
     *
     * @return string[]
     *   Resource name list.
     */
    protected function getAllowedIncludes(array $requested): array
    {
        $includes = [
            'geographic-region',
            'taxon',
        ];
        if (in_array('taxon', $requested, true)) {
            $includes = array_merge($includes, [
                'taxon-media',
                'taxon-rank',
                'taxon-group',
                'parent-taxa',
            ]);
        }
        return $includes;
    }

    /**
     * Retrieve internal helper fields used for response hydration.
     *
     * @return array<string, string>
     */
    protected function getInternalFields(array $includes = []): array
    {
        if (! $this->hasInclude($includes, 'taxon-media')) {
            return [];
        }

        return [
            '__taxon_id' => 't.id',
        ];
    }

    /**
     * Add include-dependent nested data to each response row.
     *
     * @param array<int, array<string, mixed>> $data
     * @return void
     */
    protected function augmentResponseData(array &$data, array $includes = []): void
    {
        if ($this->hasInclude($includes, 'taxon-media')) {
            $this->hydrateTaxonMedia($data);
        }
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
            'uuid' => 'ts.uuid',
            'taxon_identifier' => 't.taxon_identifier',
            'higher_geography_identifier' => 'gr.higher_geography_identifier',
            'occurrences_count' => 'ts.occurrences_count',
            'grid_square_count' => 'ts.grid_square_count',
            'first_record_date' => 'ts.first_record_date',
            'last_record_date' => 'ts.last_record_date',
            'first_recorder' => 'ts.first_recorder',
            'last_recorder' => 'ts.last_recorder',
            'first_verified_record_date' => 'ts.first_verified_record_date',
            'last_verified_record_date' => 'ts.last_verified_record_date',
            'first_verified_recorder' => 'ts.first_verified_recorder',
            'last_verified_recorder' => 'ts.last_verified_recorder',
        ];
        if ($this->hasInclude($includes, 'geographic-region')) {
            $fields['geographic_region__higher_geography'] = 'gr.higher_geography';
            $fields['geographic_region__location_type'] = 'gr.location_type';
        }

        if ($this->hasInclude($includes, 'taxon')) {
            $fields['taxon__scientific_name'] = 't.scientific_name';
            $fields['taxon__scientific_name_authorship'] = 't.scientific_name_authorship';
            $fields['taxon__scientific_name_identifier'] = 't.scientific_name_identifier';
            $fields['taxon__vernacular_name'] = 't.vernacular_name';
            $fields['taxon__id_difficulty'] = 't.id_difficulty';
            $fields['taxon__conservation_status'] = 't.conservation_status';
            $fields['taxon__rarity_category'] = 't.rarity_category';

            if ($this->hasInclude($includes, 'parent-taxa')) {
                foreach ($this->dynamicRankAliases() as $alias) {
                    $joinAlias = $this->parentTaxaJoinAlias($alias);
                    $fields[$alias . '__scientific_name'] = $joinAlias . '.scientific_name';
                    $fields[$alias . '__vernacular_name'] = $joinAlias . '.vernacular_name';
                }
            }

            if ($this->hasInclude($includes, 'taxon-group')) {
                $fields['taxon_group__external_key'] = 'tg.external_key';
                $fields['taxon_group__friendly'] = 'tg.friendly';
                $fields['taxon_group__title'] = 'tg.title';
            }

            if ($this->hasInclude($includes, 'taxon-rank')) {
                $fields['taxon_rank__rank'] = 'tr.rank';
                $fields['taxon_rank__abbr'] = 'tr.abbr';
                $fields['taxon_rank__sort_order'] = 'tr.sort_order';
            }
        }

        return $fields;
    }

    /**
     * Builds the base query used for the API.
     *
     * Inner-joins `taxa` (blocked/soft-deleted rows excluded) and left-joins
     * `geographic_regions` (soft-deleted regions excluded) so the region key column is
     * always available. Joins for `parent-taxa`, `taxon-group`, and `taxon-rank` are only
     * added when the corresponding include is requested.
     *
     * @return object
     *   The query builder instance.
     */
    protected function getBuilder(object $db, array $includes = []): BaseBuilder
    {
        $builder = $db->table('taxon_stats ts')
            ->select($this->getFieldSql($includes), false)
            ->join('taxa t', 't.id = ts.taxon_id AND t.deleted_at IS NULL AND t.blocked = 0')
            ->join('geographic_regions gr', 'gr.id = ts.geographic_region_id AND gr.deleted_at IS NULL', 'left');

        if ($this->hasInclude($includes, 'parent-taxa')) {
            foreach ($this->dynamicRankAliases() as $alias) {
                $joinAlias = $this->parentTaxaJoinAlias($alias);
                $builder->join("taxa {$joinAlias}", "{$joinAlias}.id = t.{$alias}_id", 'left');
            }
        }
        if ($this->hasInclude($includes, 'taxon-group')) {
            $builder->join('taxon_groups tg', 'tg.id = t.taxon_group_id', 'left');
        }
        if ($this->hasInclude($includes, 'taxon-rank')) {
            $builder->join('taxon_ranks tr', 'tr.id = t.taxon_rank_id', 'left');
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
        return 'uuid';
    }

    /**
     * Name of the column for sorting if not otherwise specified.
     *
     * @return string
     */
    protected function getDefaultSortColumn(): string
    {
        return 'uuid';
    }
}
