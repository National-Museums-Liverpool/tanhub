<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;

/**
 * API endpoints for taxon names.
 */
class TaxonNames extends ApiResourceController
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
            'uuid' => 'tn.uuid',
            'name' => 'tn.name',
            'given_name_identifier' => 'tn.given_name_identifier',
            'taxon__taxon_identifier' => 't.taxon_identifier',
            'accepted' => 'tn.accepted',
            'scientific' => 'tn.scientific',
        ];

        if ($this->hasInclude($includes, 'parent-taxa')) {
            foreach ($this->dynamicRankAliases() as $alias) {
                $fields[$alias . '__scientific_name'] = $alias . '.scientific_name';
                $fields[$alias . '__vernacular_name'] = $alias . '.vernacular_name';
            }
        }

        if ($this->hasInclude($includes, 'taxon')) {
            $fields['taxon__scientific_name'] = 't.scientific_name';
            $fields['taxon__scientific_name_authorship'] = 't.scientific_name_authorship';
            $fields['taxon__scientific_name_identifier'] = 't.scientific_name_identifier';
            $fields['taxon__vernacular_name'] = 't.vernacular_name';
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
        $builder = $db->table('taxon_names tn')
            ->select($this->getFieldSql($includes))
            ->join('taxa t', 't.id = tn.taxon_id AND t.deleted_at IS NULL AND t.blocked = 0');

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
        return 'name';
    }
}
