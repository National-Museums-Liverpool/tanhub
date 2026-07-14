<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * API endpoints for taxon ranks.
 */
class TaxonRanks extends ApiController
{
    /**
     * List taxon ranks.
     */
    public function index(): ResponseInterface
    {
        $pagination = $this->getPagination();

        if ($pagination instanceof ResponseInterface) {
            return $pagination;
        }

        $sorts = $this->getSorts([
            'rank' => 'rank',
            'abbr' => 'abbr',
            'sort_order' => 'sort_order',
        ], 'rank');

        if ($sorts instanceof ResponseInterface) {
            return $sorts;
        }

        $filters = $this->getFilters([
            'rank' => 'rank',
            'abbr' => 'abbr',
            'sort_order' => 'sort_order',
        ]);

        if ($filters instanceof ResponseInterface) {
            return $filters;
        }

        $builder = db_connect()->table('taxon_ranks')
            ->select('rank, abbr, sort_order')
            ->where('deleted_at', null);

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
     * Return a single taxon rank by abbreviation.
     */
    public function show(string $abbr): ResponseInterface
    {
        $item = db_connect()->table('taxon_ranks')
            ->select('rank, abbr, sort_order')
            ->where('abbr', $abbr)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        if ($item === null) {
            return $this->respondProblem(404, 'Resource not found', "No taxon rank exists for abbreviation '{$abbr}'.");
        }

        return $this->respondItem($item);
    }
}
