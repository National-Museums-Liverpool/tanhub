<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * API endpoints for data sources.
 */
class DataSources extends ApiController
{
    /**
     * List data sources.
     */
    public function index(): ResponseInterface
    {
        $pagination = $this->getPagination();

        if ($pagination instanceof ResponseInterface) {
            return $pagination;
        }

        $sorts = $this->getSorts([
            'abbr' => 'abbr',
            'title' => 'title',
            'url' => 'url',
        ], 'abbr');

        if ($sorts instanceof ResponseInterface) {
            return $sorts;
        }

        $filters = $this->getFilters([
            'abbr' => 'abbr',
            'title' => 'title',
            'url' => 'url',
        ]);

        if ($filters instanceof ResponseInterface) {
            return $filters;
        }

        $builder = db_connect()->table('data_sources')
            ->select('abbr, title, url');

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
     * Return a single data source by abbreviation.
     */
    public function show(string $abbr): ResponseInterface
    {
        $item = db_connect()->table('data_sources')
            ->select('abbr, title, url')
            ->where('abbr', $abbr)
            ->get()
            ->getRowArray();

        if ($item === null) {
            return $this->respondProblem(404, 'Resource not found', "No data source exists for abbr '{$abbr}'.");
        }

        return $this->respondItem($item);
    }
}
