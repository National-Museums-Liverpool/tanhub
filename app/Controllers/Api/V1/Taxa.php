<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;

/**
 * API endpoints for the `taxa` resource.
 *
 * Serves `GET api/v1/taxa` (list) and `GET api/v1/taxa/{taxon_identifier}` (show); see
 * {@see ApiResourceController} for the shared pagination/sort/filter behavior and
 * {@see ApiController} for the public-read/rate-limit model that applies to all endpoints
 * in this namespace. Blocked (`blocked = 1`) and soft-deleted taxa are always excluded.
 * Supports `?include=` expansions for `parent-taxa` (dynamic per-rank self-joins, see
 * {@see ApiResourceController::dynamicRankAliases()}), `recording-scheme`, `taxon-media`
 * (nested media hydrated post-query via {@see ApiResourceController::hydrateTaxonMedia()}),
 * `taxon-group`, and `taxon-rank`.
 */
class Taxa extends ApiResourceController
{

    /**
     * Retrieve list of resources that can be included (joined) in requests.
     *
     * @return string[]
     *   Resource name list.
     */
    protected function getAllowedIncludes(array $requested): array
    {
        return [
            'parent-taxa',
            'recording-scheme',
            'taxon-media',
            'taxon-group',
            'taxon-rank',
        ];
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
            'taxon_identifier' => 't.taxon_identifier',
            'scientific_name_identifier' => 't.scientific_name_identifier',
            'scientific_name' => 't.scientific_name',
            'scientific_name_authorship' => 't.scientific_name_authorship',
            'vernacular_name' => 't.vernacular_name',
            'id_difficulty' => 't.id_difficulty',
            'conservation_status' => 't.conservation_status',
            'taxon_remarks' => 't.taxon_remarks',
            'rarity_group_name' => 't.rarity_group_name',
            'rarity_category' => 't.rarity_category',
        ];

        if ($this->hasInclude($includes, 'parent-taxa')) {
            foreach ($this->dynamicRankAliases() as $alias) {
                $joinAlias = $this->parentTaxaJoinAlias($alias);
                $fields[$alias . '__scientific_name'] = $joinAlias . '.scientific_name';
                $fields[$alias . '__vernacular_name'] = $joinAlias . '.vernacular_name';
            }
        }

        if ($this->hasInclude($includes, 'recording-scheme')) {
            $fields['recording_scheme__external_key'] = 'rs.external_key';
            $fields['recording_scheme__title'] = 'rs.title';
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
     * Excludes blocked and soft-deleted taxa unconditionally. Joins for `parent-taxa`,
     * `recording-scheme`, `taxon-group`, and `taxon-rank` are only added when the
     * corresponding include is requested, so the query cost scales with what the
     * caller actually asked for.
     *
     * @return object
     *   The query builder instance.
     */
    protected function getBuilder(object $db, array $includes = []): BaseBuilder
    {
        $builder = $db->table('taxa t')
                ->select($this->getFieldSql($includes), false)
            ->where('t.deleted_at', null, false)
            ->where('t.blocked', 0, false);
        if ($this->hasInclude($includes, 'parent-taxa')) {
            foreach ($this->dynamicRankAliases() as $alias) {
                $joinAlias = $this->parentTaxaJoinAlias($alias);
                $builder->join("taxa {$joinAlias}", "{$joinAlias}.id = t.{$alias}_id", 'left');
            }
        }
        if ($this->hasInclude($includes, 'recording-scheme')) {
            $builder->join('recording_schemes rs', 'rs.id = t.recording_scheme_id', 'left');
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
        return 'taxon_identifier';
    }

    /**
     * Name of the column for sorting if not otherwise specified.
     *
     * @return string
     */
    protected function getDefaultSortColumn(): string
    {
        return 'scientific_name';
    }
}
