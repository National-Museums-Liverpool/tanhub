<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Shared API behavior for v1 endpoints.
 */
abstract class ApiResourceController extends ApiController
{
    abstract protected function allowedFields(array $includes = []): array;

    abstract protected function getBuilder(object $db, array $includes = []): BaseBuilder;

    abstract protected function getDefaultKeyColumn(): string;

    abstract protected function getDefaultSortColumn(): string;

    protected function getAllowedIncludes(): array
    {
        return [];
    }

    /**
     * Use the default field list as the filterable field list.
     *
     * Override this function if the filterable fields need to be different.
     *
     * @param array $includes
     *   Resources being included in the request.
     *
     * @return array
     *   Array of field identifiers and their corresponding query columns.
     */
    protected function allowedFilters(array $includes = []): array
    {
        return $this->allowedFields($includes);
    }

    /**
     * Use the default field list as the sortable field list.

     * Override this function if the sortable fields need to be different.
     *
     * @param array $includes
     *   Resources being included in the request.
     *
     * @return array
     *   Array of field identifiers and their corresponding query columns.
     */    protected function allowedSorts(array $includes = []): array
    {
        return $this->allowedFields($includes);
    }

    /**
     * List geographic regions.
     */
    public function index(): ResponseInterface
    {
        $includes = $this->getIncludes();

        if ($includes instanceof ResponseInterface) {
            return $includes;
        }

        $pagination = $this->getPagination();

        if ($pagination instanceof ResponseInterface) {
            return $pagination;
        }

        $sorts = $this->getSorts($this->allowedSorts($includes));

        if ($sorts instanceof ResponseInterface) {
            return $sorts;
        }

        $filters = $this->getFilters($this->allowedFilters($includes));

        if ($filters instanceof ResponseInterface) {
            return $filters;
        }

        $db = db_connect();
        $builder = $this->getBuilder($db, $includes);

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
     * Return a single geographic region by higher geography identifier.
     */
    public function show(string $key): ResponseInterface
    {
        $db = db_connect();

        $item = $this->getBuilder($db)
            ->where($this->getDefaultKeyColumn(), $key)
            ->get()
            ->getRowArray();

        if ($item === null) {
            // Return a 404 problem response if the item is not found.
            $resourceClass = (new \ReflectionClass($this))->getShortName();
            return $this->respondProblem(404, 'Resource not found', "No {$resourceClass} exists for key '{$key}'.");
        }

        return $this->respondItem($item);
    }

    /**
     * Resolve configured taxon ranks to aliases that are present as *_id columns on taxa.
     *
     * Missing columns are skipped and logged as warnings to prevent runtime SQL errors
     * when include=parent_taxa is requested.
     *
     * @param array<int, string> $ranks
     * @return array<int, string>
     */
    protected function resolveAvailableTaxonRankAliases(array $ranks): array
    {
        $aliases = [];

        foreach ($ranks as $rank) {
            $alias = $this->normaliseTaxonRankAlias($rank);

            if ($alias === '') {
                continue;
            }

            $aliases[] = $alias;
        }

        $aliases = array_values(array_unique($aliases));

        if ($aliases === []) {
            return [];
        }

        $db = db_connect();
        $valid = [];
        $missingColumns = [];

        foreach ($aliases as $alias) {
            $column = $alias . '_id';

            if ($db->fieldExists($column, 'taxa')) {
                $valid[] = $alias;
                continue;
            }

            $missingColumns[] = $column;
        }

        if ($missingColumns !== []) {
            log_message(
                'warning',
                'Configured import.taxonRanks columns missing on taxa table; parent_taxa fields disabled for: {columns}',
                ['columns' => implode(', ', $missingColumns)]
            );
        }

        return $valid;
    }

    /**
     * Convert a rank label to a normalised alias suitable for database column naming.
     */
    protected function normaliseTaxonRankAlias(string $rank): string
    {
        $alias = strtolower(trim($rank));
        $alias = preg_replace('/[^a-z0-9]+/i', '_', $alias);

        return trim((string) $alias, '_');
    }

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
     * Convert the allowed fields to an SQL column list.
     *
     * @param array $includes
     *   Resources being included in the request.
     *
     * @return string
     *   SQL field list.
     */
    protected function getFieldSql(array $includes = []): string
    {
        $fields = $this->allowedFields($includes);
        $selects = [];

        foreach ($fields as $alias => $column) {
            if ($alias === $column) {
                $selects[] = $column;
            } else {
                $selects[] = "{$column} AS {$alias}";
            }
        }

        return implode(', ', $selects);
    }

    /**
     * Parse sort fields from the sort query parameter.
     *
     * @param array<string, string> $allowedSorts
     * @return array<int, array<string, string>>|ResponseInterface
     */
    protected function getSorts(array $allowedSorts): array|ResponseInterface
    {
        $sortRaw = trim((string) $this->request->getGet('sort'));

        if ($sortRaw === '') {
            return [[
                'column' => $allowedSorts[$this->getDefaultSortColumn()] ?? $this->getDefaultSortColumn(),
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
            if (in_array($field, ['limit', 'offset', 'sort', 'include'], true)) {
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

     /**
     * @return array<string, bool>|ResponseInterface
     */
    private function getIncludes(): array|ResponseInterface
    {
        $raw = (string) ($this->request->getGet('include') ?? '');

        if (trim($raw) === '') {
            return [];
        }

        $parts = array_filter(array_map('trim', explode(',', strtolower($raw))), static fn (string $item): bool => $item !== '');
        $supported = $this->getAllowedIncludes();
        $includes = [];

        foreach ($parts as $part) {
            if (! in_array($part, $supported, true)) {
                return $this->respondProblem(400, 'Invalid include parameter', "Unsupported include value '{$part}'.");
            }

            $includes[$part] = true;
        }

        return $includes;
    }

    /**
     * @param array<string, bool> $includes
     */
    protected function hasInclude(array $includes, string $name): bool
    {
        return isset($includes[$name]) && $includes[$name] === true;
    }


    /**
     * Get a list of taxon ranks from config.
     *
     * @return array<int, string>
     */
    protected function dynamicRankAliases(): array
    {
        $ranks = config('Import')->taxonRanks ?? [];
        $ranks = is_array($ranks) ? $ranks : explode(',', (string) $ranks);
        $scalarRanks = array_values(array_filter($ranks, static fn ($rank): bool => is_scalar($rank)));
        $rankStrings = array_map(static fn ($rank): string => (string) $rank, $scalarRanks);

        return $this->resolveAvailableTaxonRankAliases($rankStrings);
    }
}
