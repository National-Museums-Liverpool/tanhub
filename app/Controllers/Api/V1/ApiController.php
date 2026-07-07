<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Controller;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shared API behavior for v1 endpoints.
 */
abstract class ApiController extends Controller
{
    /**
     * Build a list response envelope.
     *
     * @param array<int, array<string, mixed>> $data
     */
    protected function respondList(array $data, int $total, int $limit, int $offset): ResponseInterface
    {
        $self = $this->buildLink(['limit' => $limit, 'offset' => $offset]);
        $next = ($offset + $limit) < $total ? $this->buildLink(['limit' => $limit, 'offset' => $offset + $limit]) : null;
        $prevOffset = $offset - $limit;
        $prev = $prevOffset >= 0 ? $this->buildLink(['limit' => $limit, 'offset' => $prevOffset]) : null;

        return $this->response->setJSON([
            'data' => $data,
            'meta' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($data),
                'total' => $total,
            ],
            'links' => [
                'self' => $self,
                'next' => $next,
                'prev' => $prev,
            ],
        ]);
    }

    /**
     * Build a single-item JSON response.
     *
     * @param array<string, mixed> $item
     */
    protected function respondItem(array $item): ResponseInterface
    {
        return $this->response->setJSON($item);
    }

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

    /**
     * Parse and validate pagination query parameters.
     *
     * @return array<string, int>|ResponseInterface
     */
    protected function getPagination(): array|ResponseInterface
    {
        $limitRaw = $this->request->getGet('limit');
        $offsetRaw = $this->request->getGet('offset');

        $limit = ($limitRaw === null || $limitRaw === '') ? 1000 : (int) $limitRaw;
        $offset = ($offsetRaw === null || $offsetRaw === '') ? 0 : (int) $offsetRaw;

        if ($limit < 1 || $limit > 10000) {
            return $this->respondProblem(400, 'Invalid pagination parameter', 'limit must be between 1 and 10000.');
        }

        if ($offset < 0) {
            return $this->respondProblem(400, 'Invalid pagination parameter', 'offset must be 0 or greater.');
        }

        return [
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Parse sort fields from the sort query parameter.
     *
     * @param array<string, string> $allowedSorts
     * @return array<int, array<string, string>>|ResponseInterface
     */
    protected function getSorts(array $allowedSorts, string $defaultColumn): array|ResponseInterface
    {
        $sortRaw = trim((string) $this->request->getGet('sort'));

        if ($sortRaw === '') {
            return [[
                'column' => $allowedSorts[$defaultColumn],
                'direction' => 'ASC',
            ]];
        }

        $parts = array_filter(array_map('trim', explode(',', $sortRaw)), static fn (string $part): bool => $part !== '');

        if ($parts === []) {
            return $this->respondProblem(400, 'Invalid sort parameter', 'sort must contain at least one field name.');
        }

        $sorts = [];

        foreach ($parts as $part) {
            $descending = str_starts_with($part, '-');
            $field = $descending ? substr($part, 1) : $part;

            if ($field === '' || ! isset($allowedSorts[$field])) {
                return $this->respondProblem(400, 'Invalid sort parameter', 'Unsupported sort field: ' . $field . '.');
            }

            $sorts[] = [
                'column' => $allowedSorts[$field],
                'direction' => $descending ? 'DESC' : 'ASC',
            ];
        }

        return $sorts;
    }

    /**
     * Parse field[operator] filters from query parameters.
     *
     * @param array<string, string> $allowedFilters
     * @return array<int, array<string, mixed>>|ResponseInterface
     */
    protected function getFilters(array $allowedFilters): array|ResponseInterface
    {
        $query = $this->request->getGet();
        $filters = [];
        $allowedOperators = ['eq', 'in', 'contains', 'gte', 'lte'];

        foreach ($query as $field => $value) {
            if (in_array($field, ['limit', 'offset', 'sort'], true)) {
                continue;
            }

            if (! isset($allowedFilters[$field])) {
                return $this->respondProblem(400, 'Invalid filter parameter', "Filter field '{$field}' is not supported for this resource.");
            }

            if (is_array($value)) {
                foreach ($value as $operator => $raw) {
                    if (! in_array((string) $operator, $allowedOperators, true)) {
                        return $this->respondProblem(400, 'Invalid filter parameter', "Filter operator '{$operator}' is not supported.");
                    }

                    $filters[] = [
                        'column' => $allowedFilters[$field],
                        'operator' => (string) $operator,
                        'value' => $raw,
                    ];
                }

                continue;
            }

            $filters[] = [
                'column' => $allowedFilters[$field],
                'operator' => 'eq',
                'value' => $value,
            ];
        }

        return $filters;
    }

    /**
     * Apply parsed filters to a query builder.
     *
     * @param array<int, array<string, mixed>> $filters
     */
    protected function applyFilters(BaseBuilder $builder, array $filters): void
    {
        foreach ($filters as $filter) {
            $column = (string) $filter['column'];
            $operator = (string) $filter['operator'];
            $value = $filter['value'];

            switch ($operator) {
                case 'eq':
                    $builder->where($column, $value);
                    break;

                case 'in':
                    $values = is_array($value)
                        ? $value
                        : array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $v): bool => $v !== '');

                    $builder->whereIn($column, $values === [] ? [''] : $values);
                    break;

                case 'contains':
                    $builder->like($column, (string) $value, 'both', null, true);
                    break;

                case 'gte':
                    $builder->where($column . ' >=', $value);
                    break;

                case 'lte':
                    $builder->where($column . ' <=', $value);
                    break;
            }
        }
    }

    /**
     * Apply parsed sorts to a query builder.
     *
     * @param array<int, array<string, string>> $sorts
     */
    protected function applySorts(BaseBuilder $builder, array $sorts): void
    {
        foreach ($sorts as $sort) {
            $builder->orderBy($sort['column'], $sort['direction']);
        }
    }

    /**
     * Build a relative API link preserving existing query parameters.
     *
     * @param array<string, int> $overrides
     */
    private function buildLink(array $overrides = []): string
    {
        $path = '/' . ltrim($this->request->getUri()->getPath(), '/');
        $query = $this->request->getGet();

        foreach ($overrides as $key => $value) {
            $query[$key] = $value;
        }

        $queryString = http_build_query($query);

        if ($queryString === '') {
            return $path;
        }

        return $path . '?' . $queryString;
    }
}
