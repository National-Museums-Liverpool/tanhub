# TanHub REST API Specification (v1)

## 1. Overview

TanHub provides a read-only REST API for reporting and discovery use cases.

- Base path: `/api/v1`
- Data format: JSON
- Access: unauthenticated and authenticated
- Primary behavior: `GET` endpoints for list and single-resource retrieval

The API is designed around stable, resource-level unique identifiers rather than internal database primary keys.

## 2. Authentication and Authorization

Authentication is optional for all `GET` endpoints.

- Unauthenticated requests are rate limited by IP address.
- Authenticated requests use Bearer JWT tokens and have separate (configurable) rate-limiting behavior.

### 2.1 JWT endpoints

TanHub should expose conventional JWT lifecycle endpoints under the API base path:

- `POST /api/v1/auth/token` to obtain an access token (and optional refresh token)
- `POST /api/v1/auth/token/refresh` to refresh an access token
- `POST /api/v1/auth/token/revoke` to revoke token(s)

Request and response payload shapes can follow standard OAuth2/JWT-compatible patterns used by the TanHub auth layer.

### 2.2 Bearer token usage

Clients send:

`Authorization: Bearer <access_token>`

## 3. Rate Limiting

- Unauthenticated default limit: `20 requests per 20 seconds` per IP
- Limits must be configurable
- Authenticated request limits must be configurable and can be higher than anonymous limits

Rate-limit headers should be included where feasible, for example:

- `X-RateLimit-Limit`
- `X-RateLimit-Remaining`
- `X-RateLimit-Reset`

## 4. Resource Model

Each resource supports:

- List endpoint: `GET /api/v1/<resource>`
- Single endpoint: `GET /api/v1/<resource>/{unique_identifier}`

### 4.1 Resources and unique identifiers

- `data-sources`: `abbr`
- `geographic-regions`: `higher_geography_identifier`
- `occurrences`: `unique_key`
- `recording-schemes`: `external_key`
- `taxa`: `taxon_identifier`
- `taxon-groups`: `external_key`
- `taxon-names`: `uuid`
- `grid-square-stats`: `uuid`
- `taxon-stats`: `uuid`
- `taxon-year-stats`: `uuid`

## 5. Pagination

List endpoints use limit/offset pagination.

- Query params:
	- `limit` (default `1000`, max `10000`, both configurable)
	- `offset` (zero-based; default `0`)

List responses are wrapped with `data`, `meta`, and `links`.

### 5.1 Example list response shape

```json
{
	"data": [
		{
			"uuid": "f2bf2f93-2e12-4e40-8af4-e4b617e89e0a",
			"square": "SU12A"
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 24567
	},
	"links": {
		"self": "/api/v1/grid-square-stats?limit=1000&offset=0",
		"next": "/api/v1/grid-square-stats?limit=1000&offset=1000",
		"prev": null
	}
}
```

## 6. Filtering

Filtering is available on list endpoints using field-based query parameters.

### 6.1 Operators

- `field[eq]=value` equals (default behavior)
- `field[in]=a,b,c` in-list match
- `field[contains]=text` substring match
- `field[gte]=value` greater-than-or-equal
- `field[lte]=value` less-than-or-equal

`contains` matching is case-insensitive.

### 6.2 Filterable fields rule

All exposed resource fields are filterable except:

- `blocked`
- `blocked_reason`
- `created_at`
- `updated_at`
- `deleted_at`

### 6.3 Join-table convenience behavior

Join tables are not exposed as standalone API resources.

For example, when querying `occurrences`, clients can filter by geographic region via `higher_geography_identifier` without directly querying `geographic_regions_occurrences`.

## 7. Sorting

Sorting is supported via:

- `sort=field` ascending
- `sort=-field` descending

Multiple sort fields can be comma-separated:

- `sort=from_date,-unique_key`

## 8. Blocking Rules

- Blocked occurrences are excluded from API results.
- Blocked taxa are excluded from API results.
- `taxon-stats` and `taxon-year-stats` must also exclude rows for blocked taxa.

## 9. Response Conventions

### 9.1 Single resource response

Single resource endpoints return a JSON object for the resource.

### 9.2 List response envelope

List endpoints return:

- `data`: array of resource objects
- `meta`: pagination metadata
- `links`: pagination navigation links

### 9.3 Date and time format

Datetime fields use ISO 8601 in UTC.

## 10. Error Handling

Errors use `application/problem+json` based on RFC 9457/7807.

### 10.1 Example

```json
{
	"type": "https://api.tanhub.example/problems/invalid-filter",
	"title": "Invalid filter parameter",
	"status": 400,
	"detail": "Filter field 'foo' is not supported for this resource.",
	"instance": "/api/v1/taxa?foo[eq]=bar"
}
```

## 11. Endpoint Summary

- `GET /api/v1/data-sources`
- `GET /api/v1/data-sources/{abbr}`
- `GET /api/v1/geographic-regions`
- `GET /api/v1/geographic-regions/{higher_geography_identifier}`
- `GET /api/v1/occurrences`
- `GET /api/v1/occurrences/{unique_key}`
- `GET /api/v1/recording-schemes`
- `GET /api/v1/recording-schemes/{external_key}`
- `GET /api/v1/taxa`
- `GET /api/v1/taxa/{taxon_identifier}`
- `GET /api/v1/taxon-groups`
- `GET /api/v1/taxon-groups/{external_key}`
- `GET /api/v1/taxon-names`
- `GET /api/v1/taxon-names/{uuid}`
- `GET /api/v1/grid-square-stats`
- `GET /api/v1/grid-square-stats/{uuid}`
- `GET /api/v1/taxon-stats`
- `GET /api/v1/taxon-stats/{uuid}`
- `GET /api/v1/taxon-year-stats`
- `GET /api/v1/taxon-year-stats/{uuid}`

## 12. Non-Goals for v1

- No write operations (`POST`, `PUT`, `PATCH`, `DELETE`) on core data resources
- No direct public endpoints for join tables