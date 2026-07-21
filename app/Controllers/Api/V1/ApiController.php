<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shared API behavior for v1 endpoints.
 */
abstract class ApiController extends Controller
{
    /**
     * Build an RFC 9457 problem response.
     */
    protected function respondProblem(int $status, string $title, string $detail, ?string $type = null): ResponseInterface
    {
        $problemType = $type ?? 'https://api.tanhub.example/problems/' . strtolower(str_replace(' ', '-', $title));

        $payload = [
            'type' => $problemType,
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => $this->request->getUri()->getPath() . ($this->request->getUri()->getQuery() !== '' ? '?' . $this->request->getUri()->getQuery() : ''),
        ];

        return $this->response
            ->setStatusCode($status)
            ->setContentType('application/problem+json')
            ->setBody((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
    }
}
