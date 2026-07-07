<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * API endpoints for taxon names.
 */
class TaxonNames extends ApiController
{
    /**
     * List taxon names.
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
            'uuid' => 'uuid',
            'name' => 'name',
            'scientific_name_identifier' => 'scientific_name_identifier',
            'accepted' => 'accepted',
            'scientific' => 'scientific',
        ], 'name');

        if ($sorts instanceof ResponseInterface) {
            return $sorts;
        }

        $filters = $this->getFilters([
            'uuid' => 'uuid',
            'taxon_identifier' => '__taxon_identifier__',
            'name' => 'name',
            'scientific_name_identifier' => 'scientific_name_identifier',
            'accepted' => 'accepted',
            'scientific' => 'scientific',
        ]);

        if ($filters instanceof ResponseInterface) {
            return $filters;
        }

        $standardFilters = [];
        $taxonIdentifierFilters = [];

        foreach ($filters as $filter) {
            if ($filter['column'] === '__taxon_identifier__') {
                $taxonIdentifierFilters[] = $filter;
                continue;
            }

            $standardFilters[] = $filter;
        }

        $builder = $db->table('taxon_names')
            ->select('uuid, (SELECT taxon_identifier FROM ' . $prefix . 'taxa WHERE id = taxon_id) AS taxon_identifier, name, scientific_name_identifier, accepted, scientific', false)
            ->where('deleted_at', null)
            ->where('taxon_id IN (SELECT id FROM ' . $prefix . 'taxa WHERE deleted_at IS NULL AND blocked = 0)', null, false);

        $this->applyFilters($builder, $standardFilters);

        foreach ($taxonIdentifierFilters as $filter) {
            $operator = (string) $filter['operator'];
            $value = $filter['value'];

            if ($operator === 'eq') {
                $builder->where('taxon_id IN (SELECT id FROM ' . $prefix . 'taxa WHERE taxon_identifier = ' . $db->escape($value) . ' AND deleted_at IS NULL AND blocked = 0)', null, false);
                continue;
            }

            if ($operator === 'in') {
                $values = is_array($value)
                    ? $value
                    : array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $v): bool => $v !== '');
                $escaped = array_map(static fn ($v): string => $db->escape((string) $v), $values);

                if ($escaped !== []) {
                    $builder->where('taxon_id IN (SELECT id FROM ' . $prefix . 'taxa WHERE taxon_identifier IN (' . implode(',', $escaped) . ') AND deleted_at IS NULL AND blocked = 0)', null, false);
                }

                continue;
            }

            if ($operator === 'contains') {
                $like = '%' . $db->escapeLikeString(strtolower((string) $value)) . '%';
                $builder->where('taxon_id IN (SELECT id FROM ' . $prefix . 'taxa WHERE LOWER(taxon_identifier) LIKE ' . $db->escape($like) . " ESCAPE '!' AND deleted_at IS NULL AND blocked = 0)", null, false);
            }
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
     * Return a single taxon name by UUID.
     */
    public function show(string $uuid): ResponseInterface
    {
        $db = db_connect();
        $prefix = $db->getPrefix();

        $item = $db->table('taxon_names')
            ->select('uuid, (SELECT taxon_identifier FROM ' . $prefix . 'taxa WHERE id = taxon_id) AS taxon_identifier, name, scientific_name_identifier, accepted, scientific', false)
            ->where('uuid', $uuid)
            ->where('deleted_at', null)
            ->where('taxon_id IN (SELECT id FROM ' . $prefix . 'taxa WHERE deleted_at IS NULL AND blocked = 0)', null, false)
            ->get()
            ->getRowArray();

        if ($item === null) {
            return $this->respondProblem(404, 'Resource not found', "No taxon name exists for uuid '{$uuid}'.");
        }

        return $this->respondItem($item);
    }
}
