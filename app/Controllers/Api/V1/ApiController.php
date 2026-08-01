<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base controller for all `api/v1/*` endpoints.
 *
 * Does not implement authentication itself: routes under `api/v1` are public
 * reads protected only by the `cors` and `api-rate-limit` filters (see
 * `app/Config/Routes.php`). A bearer token issued via {@see AuthTokens::token()}
 * is optional and, when present and valid, simply grants a higher rate-limit
 * tier (see {@see \App\Filters\ApiRateLimit}) rather than unlocking access.
 * Subclasses that expose listable/filterable resources should extend
 * {@see ApiResourceController} instead of this class directly.
 */
abstract class ApiController extends Controller
{
    /**
     * Build an RFC 9457 (application/problem+json) error response.
     *
     * Used by every endpoint to report validation and lookup failures with a
     * consistent machine-readable shape, including a `type` URI derived from
     * the title and an `instance` pointing at the request path/query that
     * triggered the problem.
     *
     * @param int         $status Standard HTTP status code to set on the response, e.g. 400, 404, 429.
     * @param string      $title  Short, human-readable problem summary (used to derive the default `type` URI).
     * @param string      $detail Specific, request-context detail describing what went wrong.
     * @param string|null $type   Optional override for the problem `type` URI; defaults to a slug built from $title.
     *
     * @return ResponseInterface JSON response with `application/problem+json` content type and the given status code.
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
