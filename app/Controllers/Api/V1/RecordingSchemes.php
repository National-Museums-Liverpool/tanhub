<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * API endpoints for recording schemes.
 */
class RecordingSchemes extends ApiController
{
    /**
     * List recording schemes.
     */
    public function index(): ResponseInterface
    {
        $pagination = $this->getPagination();

        if ($pagination instanceof ResponseInterface) {
            return $pagination;
        }

        $sorts = $this->getSorts([
            'external_key' => 'external_key',
            'title' => 'title',
        ], 'title');

        if ($sorts instanceof ResponseInterface) {
            return $sorts;
        }

        $filters = $this->getFilters([
            'external_key' => 'external_key',
            'title' => 'title',
        ]);

        if ($filters instanceof ResponseInterface) {
            return $filters;
        }

        $builder = db_connect()->table('recording_schemes')
            ->select('external_key, title')
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
     * Return a single recording scheme by external key.
     */
    public function show(string $externalKey): ResponseInterface
    {
        $item = db_connect()->table('recording_schemes')
            ->select('external_key, title')
            ->where('external_key', $externalKey)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        if ($item === null) {
            return $this->respondProblem(404, 'Resource not found', "No recording scheme exists for external_key '{$externalKey}'.");
        }

        return $this->respondItem($item);
    }
}
