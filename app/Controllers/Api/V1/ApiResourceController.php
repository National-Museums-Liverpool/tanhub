<?php

namespace App\Controllers\Api\V1;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\RawSql;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Base class implementing the shared list/show REST pattern for `api/v1` resources.
 *
 * Concrete subclasses (e.g. {@see Taxa}, {@see Occurrences}) only need to declare
 * their table/join query ({@see self::getBuilder()}), the columns exposed to API
 * consumers ({@see self::getAllowedFields()}), and the default key/sort columns;
 * this class then provides, uniformly for every resource:
 * - `GET` list endpoint with pagination ({@see self::getPagination()}), sorting
 *   (`?sort=field,-other`, see {@see self::getSorts()}), and filtering
 *   (`?field[operator]=value`, see {@see self::getFilters()} and
 *   {@see self::applyFilters()}) via {@see self::index()}.
 * - `GET` single-item lookup by the resource's natural key via {@see self::show()}.
 * - Optional `?include=` expansion of related resources, validated against
 *   {@see self::getAllowedIncludes()} and exposed to field/query building via
 *   {@see self::hasInclude()}.
 * - RFC 9457 problem responses for invalid pagination/sort/filter/include
 *   parameters and 404s on missing items (inherited from {@see ApiController}).
 *
 * All filter values are passed through the database driver's `escape()` via
 * {@see self::applyFilters()} to prevent SQL injection, since filter values are
 * user-supplied query parameters.
 */
abstract class ApiResourceController extends ApiController
{
    /**
     * Parsed reporting-only query option for the current request.
     *
     * A null value means that the resource does not support the option.
     *
     * @var bool|null
     */
    private ?bool $reportingOnly = null;

    /**
     * Map of field identifiers exposed in API responses to their SQL column/expression.
     *
     * Used both to build the `SELECT` list (via {@see self::getFieldSql()}) and,
     * by default, as the set of fields allowed for filtering/sorting (see
     * {@see self::allowedFilters()} and {@see self::allowedSorts()}). Keys are the
     * public API field names; values are the qualified column or SQL expression
     * to select. Subclasses vary the returned set based on `$includes` so that
     * joined-in fields are only selected when the corresponding `?include=` is
     * requested.
     *
     * @param array<int, string> $includes Requested/validated include names for this request.
     * @return array<string, string> Map of API field name => SQL column/expression.
     */
    abstract protected function getAllowedFields(array $includes = []): array;

    /**
     * Build the base query builder for this resource, including any `?include=`-dependent joins.
     *
     * @param object $db       Active database connection, as returned by `db_connect()`.
     * @param array<int, string> $includes Requested/validated include names for this request.
     * @return BaseBuilder Query builder with the resource's SELECT/FROM/JOIN clauses applied.
     */
    abstract protected function getBuilder(object $db, array $includes = []): BaseBuilder;

    /**
     * Name of the API field used to look up a single resource by its natural key in {@see self::show()}.
     *
     * @return string API field name (not necessarily the raw DB column name).
     */
    abstract protected function getDefaultKeyColumn(): string;

    /**
     * Name of the API field used to sort results when no `?sort=` parameter is supplied.
     *
     * @return string API field name (not necessarily the raw DB column name).
     */
    abstract protected function getDefaultSortColumn(): string;

    /**
     * List the resource names this controller accepts in the `?include=` query parameter.
     *
     * Override in subclasses that support joined/related data; the base
     * implementation permits no includes. Requested include values not present
     * in the returned list cause {@see self::getIncludes()} to return a 400
     * problem response.
     *
     * @param array<int, string> $requested Include names requested by the client (lower-cased, split on comma).
     * @return array<int, string> Include names this controller/request combination supports.
     */
    protected function getAllowedIncludes(array $requested): array
    {
        return [];
    }

    /**
     * Override to select additional helper columns needed only for internal processing.
     *
     * Fields returned here are merged into the `SELECT` list alongside
     * {@see self::getAllowedFields()} (see {@see self::getFieldSql()}), but are stripped
     * from the response by {@see self::removeInternalFields()} before it is serialized.
     * Typically used to select a raw ID column (e.g. `__taxon_id`) needed by
     * {@see self::augmentResponseData()} to batch-hydrate nested data.
     *
     * @param array<int, string> $includes Requested/validated include names for this request.
     * @return array<string, string> Map of internal field name => SQL column/expression.
     */
    protected function getInternalFields(array $includes = []): array
    {
        return [];
    }

    /**
     * Override to add response data that cannot be derived from the base query alone.
     *
     * Called after the main query has run but before internal fields are stripped, so
     * implementations can use internal helper fields (see {@see self::getInternalFields()})
     * to batch-load and attach nested/related data (e.g. taxon media, geographic regions)
     * onto each row.
     *
     * @param array<int, array<string, mixed>> $data     Result rows, modified in place.
     * @param array<int, string>               $includes Requested/validated include names for this request.
     */
    protected function augmentResponseData(array &$data, array $includes = []): void
    {
        // Do nothing by default.
    }

    /**
     * Indicate whether this resource supports the reporting-only query option.
     *
     * @return bool True when `reporting_only` should be parsed and applied.
     */
    protected function supportsReportingOnly(): bool
    {
        return false;
    }

    /**
     * Apply the reporting-only constraint to a resource query.
     *
     * Subclasses that support reporting filtering should add any required joins in
     * {@see self::getBuilder()} and apply their rank predicate here.
     *
     * @param BaseBuilder $builder Query builder to apply conditions to.
     * @param bool        $reportingOnly Whether only reporting taxa should remain.
     */
    protected function applyReportingOnly(BaseBuilder $builder, bool $reportingOnly): void
    {
        // Resources without reporting projections have no constraint to apply.
    }

    /**
     * Return the parsed reporting-only option for the current request.
     *
     * @return bool|null True/false for supported resources, or null when unsupported.
     */
    protected function isReportingOnly(): ?bool
    {
        return $this->reportingOnly;
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
        return $this->getAllowedFields($includes);
    }

    /**
     * Use the default field list as the sortable field list.
     *
     * Override this function if the sortable fields need to be different.
     *
     * @param array $includes
     *   Resources being included in the request.
     *
     * @return array
     *   Array of field identifiers and their corresponding query columns.
     */
    protected function allowedSorts(array $includes = []): array
    {
        return $this->getAllowedFields($includes);
    }

    /**
     * Handle `GET` list requests: parse includes/pagination/sort/filter, run the query, and respond.
     *
     * Validation is performed in a fixed order (includes, pagination, sort, filter) and the
     * first invalid parameter short-circuits with a 400 problem response. On success, a
     * `COUNT(*)` of the filtered (but not yet limited) query is taken for the `meta.total`
     * value before the `LIMIT`/`OFFSET` page is fetched, so pagination links reflect the
     * full filtered result set rather than just the current page.
     *
     * @return ResponseInterface Paginated list envelope ({@see self::respondList()}) or a
     *                           400 problem response for invalid query parameters.
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

        $reportingOnly = $this->getReportingOnly();

        if ($reportingOnly instanceof ResponseInterface) {
            return $reportingOnly;
        }

        $this->reportingOnly = $reportingOnly;

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
        if ($reportingOnly !== null) {
            $this->applyReportingOnly($builder, $reportingOnly);
        }
        $this->applySorts($builder, $sorts);

        $total = (clone $builder)->countAllResults();

        $data = $builder
            ->limit($pagination['limit'], $pagination['offset'])
            ->get()
            ->getResultArray();

        $this->augmentResponseData($data, $includes);

        $this->removeInternalFields($data, $includes);

        return $this->respondList($data, $total, $pagination['limit'], $pagination['offset']);
    }

    /**
     * Handle `GET` single-item requests: look up one resource row by its natural key.
     *
     * @param string $key Value of the resource's natural key (column given by
     *                    {@see self::getDefaultKeyColumn()}), taken from the route segment.
     *
     * @return ResponseInterface Single-item JSON response ({@see self::respondItem()}), or a
     *                           404 problem response if no matching row exists, or a 400
     *                           problem response for an invalid `?include=` parameter.
     */
    public function show(string $key): ResponseInterface
    {
        $includes = $this->getIncludes();

        if ($includes instanceof ResponseInterface) {
            return $includes;
        }

        $db = db_connect();

        $builder = $this->getBuilder($db, $includes);

        $item = $builder->where($this->getDefaultKeyColumn(), $key)
            ->get()
            ->getRowArray();

        if ($item === null) {
            // Return a 404 problem response if the item is not found.
            $resourceClass = (new \ReflectionClass($this))->getShortName();
            return $this->respondProblem(404, 'Resource not found', "No {$resourceClass} exists for key '{$key}'.");
        }

        $rows = [$item];

        $this->augmentResponseData($rows, $includes);

        $this->removeInternalFields($rows, $includes);

        return $this->respondItem($rows[0]);
    }


    /**
     * Strip internal-only helper fields (e.g. `__taxon_id`, `__occurrence_id`) from response rows.
     *
     * Internal fields are selected via {@see self::getInternalFields()} purely so that
     * {@see self::augmentResponseData()} can hydrate nested data (such as taxon media) after
     * the main query runs; they are never part of the public API contract and must be
     * removed before the response is serialized.
     *
     * @param array<int, array<string, mixed>> $rows     Result rows, modified in place.
     * @param array<int, string>               $includes Requested/validated include names for this request.
     */
    protected function removeInternalFields(array &$rows, array $includes = []): void
    {
        foreach ($rows as &$row) {
            // Remove any fields that are present in the internal field list.
            foreach ($this->getInternalFields($includes) as $field => $column) {
                unset($row[$field]);
            }
        }
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
     * Convert a configured rank label (e.g. "Sub Genus") to a normalised alias (e.g. "sub_genus").
     *
     * The alias is used both as the `*_id` column suffix on `taxa` (see
     * {@see self::resolveAvailableTaxonRankAliases()}) and as the API field name prefix for
     * parent-taxa fields (e.g. `sub_genus__scientific_name`), so it must be lower-case and
     * contain only `[a-z0-9_]`.
     *
     * @param string $rank Raw rank label as configured in `Config\Import::$taxonRanks`.
     * @return string Normalised, trimmed, lower-case alias with non-alphanumeric runs collapsed to `_`.
     */
    protected function normaliseTaxonRankAlias(string $rank): string
    {
        $alias = strtolower(trim($rank));
        $alias = preg_replace('/[^a-z0-9]+/i', '_', $alias);

        return trim((string) $alias, '_');
    }

    /**
     * Build the paginated list response envelope (`data`, `meta`, `links`).
     *
     * `links.next`/`links.prev` are omitted (null) when there is no further page in that
     * direction, computed from `$total` versus the current `$limit`/`$offset` window.
     *
     * @param array<int, array<string, mixed>> $data   Rows for the current page, already filtered/sorted.
     * @param int                              $total  Total matching rows across all pages (pre-pagination count).
     * @param int                              $limit  Page size applied to this request.
     * @param int                              $offset Row offset of the current page.
     *
     * @return ResponseInterface JSON response with `data`, `meta` (limit/offset/count/total), and `links` (self/next/prev).
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
     * Build a single-item JSON response for {@see self::show()}.
     *
     * @param array<string, mixed> $item Row data to serialize as the response body.
     * @return ResponseInterface JSON response whose body is the item itself (no envelope).
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
     * Parse and validate the `limit`/`offset` query parameters.
     *
     * Defaults to a limit of 1000 and offset of 0 when omitted. `limit` must be between
     * 1 and 10000 inclusive and `offset` must be non-negative; these caps exist to bound
     * result-set size and query cost per request.
     *
     * @return array{limit: int, offset: int}|ResponseInterface Parsed pagination values, or a
     *                                                           400 problem response if invalid.
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
        $fields = array_merge($this->getInternalFields($includes), $this->getAllowedFields($includes));
        $selects = [];

        foreach ($fields as $alias => $column) {
            $quotedColumn = str_starts_with($column, '(')
                ? $column
                : implode('.', array_map(
                    static fn (string $part): string => "`{$part}`",
                    explode('.', $column)
                ));

            if ($alias === $column) {
                $selects[] = $quotedColumn;
            } else {
                $selects[] = "{$quotedColumn} AS `{$alias}`";
            }
        }

        return implode(', ', $selects);
    }

    /**
     * Parse the `?sort=` query parameter into an ordered list of columns/directions.
     *
     * Accepts a comma-separated list of API field names, each optionally prefixed with `-`
     * for descending order (e.g. `sort=name,-created_at`). Falls back to
     * {@see self::getDefaultSortColumn()} ascending when `sort` is omitted. Any field not
     * present in `$allowedSorts` is rejected with a 400 problem response, since sort columns
     * are interpolated directly into the query's `ORDER BY` clause (see
     * {@see self::applySorts()}) and must come from a trusted allow-list.
     *
     * @param array<string, string> $allowedSorts Map of API field name => SQL column/expression
     *                                             permitted for sorting (see {@see self::allowedSorts()}).
     * @return array<int, array{column: string, direction: string}>|ResponseInterface Ordered list of
     *         column/direction pairs, or a 400 problem response for an unsupported/empty sort field.
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
     * Parse `field=value` and `field[operator]=value` filters from query parameters.
     *
     * Any query parameter not in `['limit', 'offset', 'sort', 'include']` is treated as a
     * filter and must appear in `$allowedFilters`, otherwise a 400 problem response is
     * returned; this prevents filtering on arbitrary/unindexed columns. Supported operators
     * are `eq` (default, used for bare `field=value`), `in`, `contains`, `gte`, and `lte`;
     * an unrecognised operator also yields a 400 problem response.
     *
     * @param array<string, string> $allowedFilters Map of API field name => SQL column/expression
     *                                               permitted for filtering (see {@see self::allowedFilters()}).
     * @return array<int, array{column: string, operator: string, value: mixed}>|ResponseInterface
     *         Parsed filter descriptors, or a 400 problem response for an unsupported field/operator.
     */
    protected function getFilters(array $allowedFilters): array|ResponseInterface
    {
        $query = $this->request->getGet();
        $filters = [];
        $allowedOperators = ['eq', 'in', 'contains', 'gte', 'lte'];

        foreach ($query as $field => $value) {
            if (in_array($field, ['limit', 'offset', 'sort', 'include', 'reporting_only'], true)) {
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
     * Parse and validate the optional `reporting_only` query parameter.
     *
     * Supported resources default to true. Accepted values are true/false and 1/0,
     * case-insensitively; ambiguous values are rejected rather than coerced.
     *
     * @return bool|null|ResponseInterface Parsed option, null when unsupported, or a 400
     *                                     problem response for an invalid value.
     */
    protected function getReportingOnly(): bool|null|ResponseInterface
    {
        if (! $this->supportsReportingOnly()) {
            return null;
        }

        $raw = $this->request->getGet('reporting_only');

        if ($raw === null || $raw === '') {
            return true;
        }

        $value = strtolower(trim((string) $raw));

        return match ($value) {
            'true', '1' => true,
            'false', '0' => false,
            default => $this->respondProblem(
                400,
                'Invalid reporting_only parameter',
                'reporting_only must be true, false, 1, or 0.'
            ),
        };
    }

    /**
     * Apply parsed filter descriptors as `WHERE` conditions on the query builder.
     *
     * Every value is escaped through the driver's `escape()` (via {@see RawSql}) before being
     * interpolated, since filter values originate from user-supplied query parameters and
     * columns come only from the caller's allow-list — this combination is what makes the
     * raw SQL construction here safe from injection. `eq` treats a literal `null`/`'null'`
     * value as `IS NULL` rather than an equality comparison, since `= NULL` never matches in SQL.
     *
     * @param BaseBuilder $builder Query builder to apply conditions to, modified in place.
     * @param array<int, array{column: string, operator: string, value: mixed}> $filters Parsed
     *        filter descriptors from {@see self::getFilters()}.
     */
    protected function applyFilters(BaseBuilder $builder, array $filters): void
    {
        $db = db_connect();

        foreach ($filters as $filter) {
            $column = (string) $filter['column'];
            $operator = (string) $filter['operator'];
            $value = $filter['value'];

            switch ($operator) {
                case 'eq':
                    if ($value === null || $value === 'null') {
                        $builder->where(new RawSql($column . ' IS NULL'));
                        break;
                    }

                    $builder->where(new RawSql($column . ' = ' . $db->escape($value)));
                    break;

                case 'in':
                    $values = is_array($value)
                        ? $value
                        : array_filter(array_map('trim', explode(',', (string) $value)), static fn (string $v): bool => $v !== '');

                    $escapedValues = array_map(static fn ($v) => $db->escape($v), $values === [] ? [''] : $values);
                    $builder->where(new RawSql($column . ' IN (' . implode(',', $escapedValues) . ')'));
                    break;

                case 'contains':
                    $builder->where(new RawSql('LOWER(' . $column . ') LIKE ' . $db->escape('%' . mb_strtolower((string) $value, 'UTF-8') . '%')));
                    break;

                case 'gte':
                    $builder->where(new RawSql($column . ' >= ' . $db->escape($value)));
                    break;

                case 'lte':
                    $builder->where(new RawSql($column . ' <= ' . $db->escape($value)));
                    break;
            }
        }
    }

    /**
     * Apply parsed sort descriptors as `ORDER BY` clauses on the query builder.
     *
     * @param BaseBuilder $builder Query builder to apply ordering to, modified in place.
     * @param array<int, array{column: string, direction: string}> $sorts Ordered column/direction
     *        pairs from {@see self::getSorts()}.
     */
    protected function applySorts(BaseBuilder $builder, array $sorts): void
    {
        foreach ($sorts as $sort) {
            // Sort columns come from allow-lists and may include aliases/expressions.
            $builder->orderBy($sort['column'], $sort['direction'], false);
        }
    }

    /**
     * Build a relative API link for the current request, preserving existing query parameters.
     *
     * Used to construct the `links.self`/`links.next`/`links.prev` values in
     * {@see self::respondList()}, overriding just the `limit`/`offset` pair per link.
     *
     * @param array<string, int> $overrides Query parameters to override (typically `limit`/`offset`).
     * @return string Relative path plus query string, e.g. `/api/v1/taxa?limit=50&offset=50`.
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
     * Parse and validate the `?include=` query parameter against {@see self::getAllowedIncludes()}.
     *
     * Include names are lower-cased and comma-split; an unsupported name yields a 400
     * problem response so that subclasses can safely trust the returned include set when
     * deciding which joins/fields to add.
     *
     * @return array<string, bool>|ResponseInterface Map of requested include name => true,
     *         or a 400 problem response for an unsupported include value.
     */
    private function getIncludes(): array|ResponseInterface
    {
        $raw = (string) ($this->request->getGet('include') ?? '');

        if (trim($raw) === '') {
            return [];
        }

        $parts = array_filter(array_map('trim', explode(',', strtolower($raw))), static fn (string $item): bool => $item !== '');
        $supported = $this->getAllowedIncludes($parts);
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
     * Check whether a given include name was requested and validated for this request.
     *
     * @param array<string, bool> $includes Validated include map from {@see self::getIncludes()}.
     * @param string              $name     Include name to test, e.g. `'taxon-media'`.
     * @return bool True if the include was requested.
     */
    protected function hasInclude(array $includes, string $name): bool
    {
        return isset($includes[$name]) && $includes[$name] === true;
    }


    /**
     * Resolve the configured `import.taxonRanks` list to usable parent-taxa aliases.
     *
     * Reads `Config\Import::$taxonRanks` (a comma-separated string or array), normalises each
     * entry via {@see self::normaliseTaxonRankAlias()}, and filters out any alias that has no
     * corresponding `*_id` column on `taxa` (see {@see self::resolveAvailableTaxonRankAliases()}).
     * Non-scalar config entries are silently ignored.
     *
     * @return array<int, string> Aliases safe to use for `parent-taxa` joins/fields.
     */
    protected function dynamicRankAliases(): array
    {
        $ranks = config('Import')->taxonRanks ?? [];
        $ranks = is_array($ranks) ? $ranks : explode(',', (string) $ranks);
        $scalarRanks = array_values(array_filter($ranks, static fn ($rank): bool => is_scalar($rank)));
        $rankStrings = array_map(static fn ($rank): string => (string) $rank, $scalarRanks);

        return $this->resolveAvailableTaxonRankAliases($rankStrings);
    }

    /**
     * Build a SQL-safe table alias for a `parent-taxa` self-join on the given rank alias.
     *
     * @param string $rankAlias Normalised rank alias from {@see self::dynamicRankAliases()}.
     * @return string Join alias, e.g. `pt_genus`, used consistently by both {@see self::getBuilder()}
     *                and {@see self::getAllowedFields()} implementations in subclasses.
     */
    protected function parentTaxaJoinAlias(string $rankAlias): string
    {
        return 'pt_' . $rankAlias;
    }

    /**
     * Populate a nested taxon media array on each row using the internal `__taxon_id` helper field.
     *
     * Batches a single lookup via `taxonMediaReadService` for all distinct taxon IDs present
     * across `$rows`, rather than querying per-row, to keep the `taxon-media` include cheap
     * for list responses. Rows without a resolvable taxon ID get an empty array.
     *
     * @param array<int, array<string, mixed>> $rows      Result rows, modified in place; must contain
     *                                                     the internal `__taxon_id` field (see
     *                                                     {@see self::getInternalFields()}).
     * @param string                           $fieldName Response field name to populate with media data.
     */
    protected function hydrateTaxonMedia(array &$rows, string $fieldName = 'taxon_media'): void
    {
        $taxonIds = [];

        foreach ($rows as $row) {
            $taxonId = (int) ($row['__taxon_id'] ?? 0);
            if ($taxonId > 0) {
                $taxonIds[] = $taxonId;
            }
        }

        $taxonIds = array_values(array_unique($taxonIds));

        if ($taxonIds === []) {
            foreach ($rows as &$row) {
                $row[$fieldName] = [];
            }

            return;
        }

        $mediaByTaxonId = service('taxonMediaReadService')->getByTaxonIds($taxonIds);

        foreach ($rows as &$row) {
            $taxonId = (int) ($row['__taxon_id'] ?? 0);
            $row[$fieldName] = $mediaByTaxonId[$taxonId] ?? [];
        }
    }
}
