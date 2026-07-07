<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * API endpoints for occurrences.
 */
class Occurrences extends ApiController
{
    /**
     * List occurrences.
     */
    public function index(): ResponseInterface
    {
        $db = db_connect();
        $prefix = $db->getPrefix();

        $pagination = $this->getPagination();

        if ($pagination instanceof ResponseInterface) {
            return $pagination;
        }

        $sorts = $this->getSorts([
            'unique_key' => 'unique_key',
            'from_date' => 'from_date',
            'to_date' => 'to_date',
            'grid_ref' => 'grid_ref',
            'grid_ref_2km' => 'grid_ref_2km',
            'locality' => 'locality',
            'recorded_by' => 'recorded_by',
            'identified_by' => 'identified_by',
            'identification_verification_status' => 'identification_verification_status',
            'sex' => 'sex',
            'life_stage' => 'life_stage',
            'organism_quantity' => 'organism_quantity',
        ], 'from_date');

        if ($sorts instanceof ResponseInterface) {
            return $sorts;
        }

        $filters = $this->getFilters([
            'unique_key' => 'unique_key',
            'taxon_identifier' => '__taxon_identifier__',
            'taxon_name_uuid' => '__taxon_name_uuid__',
            'from_date' => 'from_date',
            'to_date' => 'to_date',
            'grid_ref' => 'grid_ref',
            'grid_ref_2km' => 'grid_ref_2km',
            'locality' => 'locality',
            'recorded_by' => 'recorded_by',
            'identified_by' => 'identified_by',
            'identification_verification_status' => 'identification_verification_status',
            'sex' => 'sex',
            'life_stage' => 'life_stage',
            'organism_quantity' => 'organism_quantity',
            'data_source_abbr' => '__data_source_abbr__',
            'higher_geography_identifier' => '__higher_geography_identifier__',
        ]);

        if ($filters instanceof ResponseInterface) {
            return $filters;
        }

        $builder = $db->table('occurrences')
            ->select(
                'unique_key,
                (SELECT taxon_identifier FROM ' . $prefix . 'taxa WHERE id = taxon_id) AS taxon_identifier,
                (SELECT uuid FROM ' . $prefix . 'taxon_names WHERE id = taxon_name_id) AS taxon_name_uuid,
                from_date,
                to_date,
                grid_ref,
                grid_ref_2km,
                locality,
                recorded_by,
                identified_by,
                identification_verification_status,
                sex,
                life_stage,
                organism_quantity,
                (SELECT abbr FROM ' . $prefix . 'data_sources WHERE id = data_source_id) AS data_source_abbr,
                                (SELECT MIN(gr.higher_geography_identifier)
                                     FROM ' . $prefix . 'geographic_regions_occurrences gro
                                     INNER JOIN ' . $prefix . 'geographic_regions gr
                                                     ON gr.id = gro.geographic_region_id
                                    WHERE gro.occurrence_id = id
                                        AND gr.deleted_at IS NULL) AS higher_geography_identifier',
                false
            )
            ->where('deleted_at', null)
            ->where('blocked', 0);

        $normalFilters = [];
        $customFilters = [];

        foreach ($filters as $filter) {
            if (str_starts_with((string) $filter['column'], '__')) {
                $customFilters[] = $filter;
                continue;
            }

            $normalFilters[] = $filter;
        }

        $this->applyFilters($builder, $normalFilters);

        foreach ($customFilters as $filter) {
            $this->applyCustomFilter($builder, $filter, $prefix, $db);
        }

        $this->applySorts($builder, $sorts);

        $total = (clone $builder)->countAllResults();

        $data = $builder
            ->limit($pagination['limit'], $pagination['offset'])
            ->get()
            ->getResultArray();

        return $this->respondList($data, $total, $pagination['limit'], $pagination['offset']);
    }

    /**
     * Return one occurrence by unique key.
     */
    public function show(string $uniqueKey): ResponseInterface
    {
        $db = db_connect();
        $prefix = $db->getPrefix();

        $item = $db->table('occurrences')
            ->select(
                'unique_key,
                (SELECT taxon_identifier FROM ' . $prefix . 'taxa WHERE id = taxon_id) AS taxon_identifier,
                (SELECT uuid FROM ' . $prefix . 'taxon_names WHERE id = taxon_name_id) AS taxon_name_uuid,
                from_date,
                to_date,
                grid_ref,
                grid_ref_2km,
                locality,
                recorded_by,
                identified_by,
                identification_verification_status,
                sex,
                life_stage,
                organism_quantity,
                (SELECT abbr FROM ' . $prefix . 'data_sources WHERE id = data_source_id) AS data_source_abbr,
                                (SELECT MIN(gr.higher_geography_identifier)
                                     FROM ' . $prefix . 'geographic_regions_occurrences gro
                                     INNER JOIN ' . $prefix . 'geographic_regions gr
                                                     ON gr.id = gro.geographic_region_id
                                    WHERE gro.occurrence_id = id
                                        AND gr.deleted_at IS NULL) AS higher_geography_identifier',
                false
            )
            ->where('unique_key', $uniqueKey)
            ->where('deleted_at', null)
            ->where('blocked', 0)
            ->get()
            ->getRowArray();

        if ($item === null) {
            return $this->respondProblem(404, 'Resource not found', "No occurrence exists for unique_key '{$uniqueKey}'.");
        }

        return $this->respondItem($item);
    }

    /**
     * @param array<string, mixed> $filter
     */
    private function applyCustomFilter($builder, array $filter, string $prefix, $db): void
    {
        $column = (string) $filter['column'];
        $operator = (string) $filter['operator'];
        $value = $filter['value'];

        if ($column === '__taxon_identifier__') {
            $this->applySubqueryFilter($builder, $operator, $value, 'taxon_id', 'id', $prefix . 'taxa', 'taxon_identifier', $db);
            return;
        }

        if ($column === '__taxon_name_uuid__') {
            $this->applySubqueryFilter($builder, $operator, $value, 'taxon_name_id', 'id', $prefix . 'taxon_names', 'uuid', $db);
            return;
        }

        if ($column === '__data_source_abbr__') {
            $this->applySubqueryFilter($builder, $operator, $value, 'data_source_id', 'id', $prefix . 'data_sources', 'abbr', $db);
            return;
        }

        if ($column === '__higher_geography_identifier__') {
            if ($operator === 'eq') {
                $builder->where('id IN (SELECT occurrence_id FROM ' . $prefix . 'geographic_regions_occurrences WHERE geographic_region_id IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE higher_geography_identifier = ' . $db->escape($value) . ' AND deleted_at IS NULL))', null, false);
                return;
            }

            if ($operator === 'in') {
                $values = is_array($value)
                    ? $value
                    : array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $v): bool => $v !== '');
                $escaped = array_map(static fn ($v): string => $db->escape((string) $v), $values);

                if ($escaped !== []) {
                    $builder->where('id IN (SELECT occurrence_id FROM ' . $prefix . 'geographic_regions_occurrences WHERE geographic_region_id IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE higher_geography_identifier IN (' . implode(',', $escaped) . ') AND deleted_at IS NULL))', null, false);
                }

                return;
            }

            if ($operator === 'contains') {
                $like = '%' . $db->escapeLikeString(strtolower((string) $value)) . '%';
                $builder->where('id IN (SELECT occurrence_id FROM ' . $prefix . 'geographic_regions_occurrences WHERE geographic_region_id IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE LOWER(CAST(higher_geography_identifier AS TEXT)) LIKE ' . $db->escape($like) . " ESCAPE '!' AND deleted_at IS NULL))", null, false);
                return;
            }

            if ($operator === 'gte') {
                $builder->where('id IN (SELECT occurrence_id FROM ' . $prefix . 'geographic_regions_occurrences WHERE geographic_region_id IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE higher_geography_identifier >= ' . $db->escape($value) . ' AND deleted_at IS NULL))', null, false);
                return;
            }

            if ($operator === 'lte') {
                $builder->where('id IN (SELECT occurrence_id FROM ' . $prefix . 'geographic_regions_occurrences WHERE geographic_region_id IN (SELECT id FROM ' . $prefix . 'geographic_regions WHERE higher_geography_identifier <= ' . $db->escape($value) . ' AND deleted_at IS NULL))', null, false);
            }
        }
    }

    /**
     * Apply eq/in/contains/gte/lte operators through a related-table subquery.
     *
     * @param mixed $value
     */
    private function applySubqueryFilter($builder, string $operator, $value, string $localColumn, string $relatedPk, string $relatedTable, string $relatedField, $db): void
    {
        if ($operator === 'eq') {
            $builder->where($localColumn . ' IN (SELECT ' . $relatedPk . ' FROM ' . $relatedTable . ' WHERE ' . $relatedField . ' = ' . $db->escape($value) . ')', null, false);
            return;
        }

        if ($operator === 'in') {
            $values = is_array($value)
                ? $value
                : array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $v): bool => $v !== '');
            $escaped = array_map(static fn ($v): string => $db->escape((string) $v), $values);

            if ($escaped !== []) {
                $builder->where($localColumn . ' IN (SELECT ' . $relatedPk . ' FROM ' . $relatedTable . ' WHERE ' . $relatedField . ' IN (' . implode(',', $escaped) . '))', null, false);
            }

            return;
        }

        if ($operator === 'contains') {
            $like = '%' . $db->escapeLikeString(strtolower((string) $value)) . '%';
            $builder->where($localColumn . ' IN (SELECT ' . $relatedPk . ' FROM ' . $relatedTable . ' WHERE LOWER(' . $relatedField . ') LIKE ' . $db->escape($like) . " ESCAPE '!')", null, false);
            return;
        }

        if ($operator === 'gte') {
            $builder->where($localColumn . ' IN (SELECT ' . $relatedPk . ' FROM ' . $relatedTable . ' WHERE ' . $relatedField . ' >= ' . $db->escape($value) . ')', null, false);
            return;
        }

        if ($operator === 'lte') {
            $builder->where($localColumn . ' IN (SELECT ' . $relatedPk . ' FROM ' . $relatedTable . ' WHERE ' . $relatedField . ' <= ' . $db->escape($value) . ')', null, false);
        }
    }
}
