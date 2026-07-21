<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;

/**
 * API endpoints for taxon year stats.
 */
class TaxonYearStats extends ApiResourceController
{
/**
     * Retrieve list of resources that can be included (joined) in requests.
     *
     * @return string[]
     *   Resource name list.
     */
    protected function getAllowedIncludes(): array
    {
        return [
            'geographic-region',
            'parent-taxa',
            'taxon',
            'taxon-group',
            'taxon-rank',
        ];
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
            'uuid' => 'uuid',
            'year' => 'year',
            'occurrences_count' => 'occurrences_count',
            'grid_square_count' => 'grid_square_count',
            'taxon__taxon_identifier' => 'taxon__taxon_identifier',
            'geographic_region__higher_geography_identifier' => 'geographic_region__higher_geography_identifier',
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

            if ($this->hasInclude($includes, 'parent-taxa')) {
                foreach ($this->dynamicRankAliases() as $alias) {
                    $fields[$alias . '__scientific_name'] = $alias . '.scientific_name';
                    $fields[$alias . '__vernacular_name'] = $alias . '.vernacular_name';
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
     * @return object
     *   The query builder instance.
     */
    protected function getBuilder(object $db, array $includes = []): BaseBuilder
    {
        $builder = $db->table('taxon_year_stats ts')
            ->select($this->getFieldSql($includes))
            ->join('taxa t', 't.id = ts.taxon_id AND t.deleted_at IS NULL AND t.blocked = 0')
            ->join('geographic_regions gr', 'gr.id = t.geographic_region_id AND gr.deleted_at IS NULL', 'left');

        if ($this->hasInclude($includes, 'parent-taxa')) {
            foreach ($this->dynamicRankAliases() as $alias) {
                $builder->join("taxa {$alias}", "{$alias}.id = t.{$alias}_id", 'left');
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
        return 'year';
    }
}
