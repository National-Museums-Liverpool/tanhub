<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;

/**
 * API endpoints for occurrences.
 */
class Occurrences extends ApiResourceController
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
            'data-source',
            'geographic-region',
            'grid-square-stats',
            'taxon',
            'taxon-name',
            'taxon-group',
            'taxon-rank',
            'parent-taxa',
        ];
    }

    /**
     * Retrieve sortable fields array.
     *
     * @return array<string, string>
     *   Array of field identifiers and their corresponding query columns.
     */
    protected function allowedFields(array $includes = []): array
    {
       $fields = [
            'unique_key' => 'o.unique_key',
            'taxon__taxon_identifier' => 't.taxon_identifier',
            'from_date' => 'o.from_date',
            'to_date' => 'o.to_date',
            'grid_ref' => 'o.grid_ref',
            'grid_ref_2km' => 'o.grid_ref_2km',
            'locality' => 'o.locality',
            'recorded_by' => 'o.recorded_by',
            'identified_by' => 'o.identified_by',
            'identification_verification_status' => 'o.identification_verification_status',
            'sex' => 'o.sex',
            'life_stage' => 'o.life_stage',
            'organism_quantity' => 'o.organism_quantity',
            'geographic_region__higher_geography_identifier' => <<<SQL
              (SELECT GROUP_CONCAT(gr.higher_geography_identifier SEPARATOR ';')
                    FROM geographic_regions_occurrences gro
                    INNER JOIN geographic_regions gr
                        ON gr.id = gro.geographic_region_id
                    WHERE gro.occurrence_id = id
                        AND gr.deleted_at IS NULL)
            SQL,
        ];

        if ($this->hasInclude($includes, 'data-source')) {
            $fields['data_source__abbr'] = 'ds.abbr';
            $fields['data_source__title'] = 'ds.title';
            $fields['data_source__url'] = 'ds.url';
        }

        if ($this->hasInclude($includes, 'grid-square-stats')) {
            $fields['grid_square_stats__easting'] = 'gss.easting';
            $fields['grid_square_stats__northing'] = 'gss.northing';
            $fields['grid_square_stats__lat'] = 'gss.lat';
            $fields['grid_square_stats__lon'] = 'gss.lon';
        }

        if ($this->hasInclude($includes, 'taxon-name')) {
            $fields['taxon_name__uuid'] = 'tn.uuid';
            $fields['taxon_name__name'] = 'tn.name';
            $fields['taxon_name__given_name_identifier'] = 'tn.given_name_identifier';
            $fields['taxon_name__accepted'] = 'tn.accepted';
            $fields['taxon_name__scientific'] = 'tn.scientific';
        }

        // Other include options all depend on taxon.
        if (! $this->hasInclude($includes, 'taxon')) {
            return $fields;
        }

        $fields['taxon__scientific_name'] = 'ta.scientific_name';
        $fields['taxon__scientific_name_authorship'] = 'ta.scientific_name_authorship';
        $fields['taxon__scientific_name_identifier'] = 'ta.scientific_name_identifier';
        $fields['taxon__vernacular_name'] = 'ta.vernacular_name';

        if ($this->hasInclude($includes, 'taxon_rank')) {
            $fields['taxon_rank__rank'] = 'tr.rank';
        }

        if ($this->hasInclude($includes, 'taxon_group')) {
            $fields['taxon_group__external_key'] = 'tg.external_key';
        }

        if ($this->hasInclude($includes, 'parent-taxa')) {
            foreach ($this->dynamicRankAliases() as $alias) {
                $fields[$alias . '_scientific_name'] = $alias . '_scientific_name';
                $fields[$alias . '_vernacular_name'] = $alias . '_vernacular_name';
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
        $builder = $db->table('occurrences o')
            ->select($this->getFieldSql($includes))
            ->join('taxa t', 't.id = o.taxon_id AND t.deleted_at IS NULL AND t.blocked = 0', 'left')
            ->join('geographic_regions gr', 'gr.id = o.geographic_region_id AND gr.deleted_at IS NULL', 'left')
            ->where('o.deleted_at IS NULL', null, false)
            ->where('o.blocked = 0', null, false);

        if ($this->hasInclude($includes, 'data-source')) {
            $builder->join('data_sources ds', 'ds.id = o.data_source_id AND ds.deleted_at IS NULL', 'left');
        }

        if ($this->hasInclude($includes, 'grid_square_stats')) {
            $builder->join('grid_square_stats gss', 'gss.square = o.grid_ref_2km', 'left');
        }

        if ($this->hasInclude($includes, 'parent-taxa')) {
            foreach ($this->dynamicRankAliases() as $alias) {
                $builder->join("taxa {$alias}", "{$alias}.id = t.{$alias}_id", 'left');
            }
        }

        if ($this->hasInclude($includes, 'taxon_name')) {
            $builder->join('taxon_names tn', 'tn.id = o.taxon_name_id AND tn.deleted_at IS NULL', 'left');
        }

        if ($this->hasInclude($includes, 'taxon_rank')) {
            $builder->join('taxon_ranks tr', 'tr.id = t.taxon_rank_id', 'left');
        }

        if ($this->hasInclude($includes, 'taxon_group')) {
            $builder->join('taxon_groups tg', 'tg.id = t.taxon_group_id', 'left');
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
        return 'unique_key';
    }

    /**
     * Name of the column for sorting if not otherwise specified.
     *
     * @return string
     */
    protected function getDefaultSortColumn(): string
    {
        return 'from_date';
    }
}
