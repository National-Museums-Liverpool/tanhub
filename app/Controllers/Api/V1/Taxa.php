<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * API endpoints for taxa.
 */
class Taxa extends ApiController
{
    /**
     * List taxa.
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
            'taxon_identifier' => 'taxon_identifier',
            'scientific_name_identifier' => 'scientific_name_identifier',
            'scientific_name' => 'scientific_name',
            'scientific_name_authorship' => 'scientific_name_authorship',
            'vernacular_name' => 'vernacular_name',
            'id_difficulty' => 'id_difficulty',
            'conservation_status' => 'conservation_status',
            'taxon_remarks' => 'taxon_remarks',
            'rarity_group_name' => 'rarity_group_name',
        ], 'scientific_name');

        if ($sorts instanceof ResponseInterface) {
            return $sorts;
        }

        $filters = $this->getFilters([
            'taxon_identifier' => 'taxon_identifier',
            'scientific_name_identifier' => 'scientific_name_identifier',
            'scientific_name' => 'scientific_name',
            'scientific_name_authorship' => 'scientific_name_authorship',
            'vernacular_name' => 'vernacular_name',
            'id_difficulty' => 'id_difficulty',
            'conservation_status' => 'conservation_status',
            'taxon_remarks' => 'taxon_remarks',
            'rarity_group_name' => 'rarity_group_name',
        ]);

        if ($filters instanceof ResponseInterface) {
            return $filters;
        }

        $builder = $db->table('taxa')
            ->select('taxon_identifier, scientific_name_identifier, scientific_name, scientific_name_authorship, vernacular_name, (SELECT external_key FROM ' . $prefix . 'taxon_groups WHERE id = taxon_group_id) AS taxon_group_external_key, id_difficulty, (SELECT external_key FROM ' . $prefix . 'recording_schemes WHERE id = recording_scheme_id) AS recording_scheme_external_key, conservation_status, taxon_remarks, rarity_group_name', false)
            ->where('deleted_at', null)
            ->where('blocked', 0);

        $this->applyFilters($builder, $filters);
        $this->applySorts($builder, $sorts);

        $total = (clone $builder)->countAllResults();

        $data = $builder
            ->limit($pagination['limit'], $pagination['offset'])
            ->get()
            ->getResultArray();

        return $this->respondList($data, $total, $pagination['limit'], $pagination['offset']);
    }

    /**
     * Return a single taxon by taxon identifier.
     */
    public function show(string $taxonIdentifier): ResponseInterface
    {
        $db = db_connect();
        $prefix = $db->getPrefix();

        $item = $db->table('taxa')
            ->select('taxon_identifier, scientific_name_identifier, scientific_name, scientific_name_authorship, vernacular_name, (SELECT external_key FROM ' . $prefix . 'taxon_groups WHERE id = taxon_group_id) AS taxon_group_external_key, id_difficulty, (SELECT external_key FROM ' . $prefix . 'recording_schemes WHERE id = recording_scheme_id) AS recording_scheme_external_key, conservation_status, taxon_remarks, rarity_group_name', false)
            ->where('taxon_identifier', $taxonIdentifier)
            ->where('deleted_at', null)
            ->where('blocked', 0)
            ->get()
            ->getRowArray();

        if ($item === null) {
            return $this->respondProblem(404, 'Resource not found', "No taxon exists for taxon_identifier '{$taxonIdentifier}'.");
        }

        return $this->respondItem($item);
    }
}
