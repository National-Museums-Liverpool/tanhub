# TanHub REST API Specification (v1)

## 1. Overview

TanHub provides a read-only REST API for reporting and discovery use cases.

For new integrators, the most common flow is:

1. Choose a resource endpoint (for example `/api/v1/taxa` or `/api/v1/occurrences`).
2. Add filters and sorting using query parameters.
3. Add include expansions where supported.
4. Page through result sets using `limit` and `offset`.
5. Follow `links.next` until no additional page is available.

- Base path: `/api/v1`
- Data format: JSON
- Access: unauthenticated and authenticated
- Primary behavior: `GET` endpoints for list and single-resource retrieval

The API is designed around stable, resource-level unique identifiers rather than internal database primary keys.

## 2. Authentication and Authorization

Authentication is optional for all `GET` endpoints.

Practical guidance:

- You can start integrating with anonymous requests.
- Add authentication when you need higher request throughput or access control.
- Endpoint behavior is otherwise consistent between anonymous and authenticated usage.

- Unauthenticated requests are rate limited by IP address.
- Authenticated requests use Bearer JWT tokens and have separate (configurable) rate-limiting behavior.

### 2.1 JWT endpoints

TanHub should expose conventional JWT lifecycle endpoints under the API base path:

- `POST /api/v1/auth/token` to obtain an access token (and optional refresh token)
- `POST /api/v1/auth/token/refresh` to refresh an access token
- `POST /api/v1/auth/token/revoke` to revoke token(s)

Request and response payload shapes can follow standard OAuth2/JWT-compatible patterns used by the TanHub auth layer.

For `POST /api/v1/auth/token`, the request uses `username` and `password`; `username` should contain the account email address.

### 2.2 Bearer token usage

Clients send:

`Authorization: Bearer <access_token>`

Example:

```http
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGciOi...
```

## 3. Rate Limiting

- Unauthenticated default limit: `20 requests per 20 seconds` per IP
- Authenticated default limit: `60 requests per 20 seconds` per authenticated user token identity
- Limits must be configurable
- Authenticated request limits must be configurable and can be higher than anonymous limits

Rate-limit headers are returned by the API throttling filter:

- `X-RateLimit-Limit`
- `X-RateLimit-Reset`

When throttled, the API returns HTTP `429 Too Many Requests` with:

- `Retry-After`
- `application/problem+json` body describing the rate-limit condition

### 3.1 Environment configuration

These environment keys control throttling behavior:

- `api.rateLimitAnonymousCapacity` (default `20`)
- `api.rateLimitAnonymousSeconds` (default `20`)
- `api.rateLimitAuthenticatedCapacity` (default `60`)
- `api.rateLimitAuthenticatedSeconds` (default `20`)

Example:

```dotenv
api.rateLimitAnonymousCapacity = 30
api.rateLimitAnonymousSeconds = 60
api.rateLimitAuthenticatedCapacity = 120
api.rateLimitAuthenticatedSeconds = 60
```

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
- `taxon-ranks`: `abbr`
- `grid-square-stats`: `uuid`
- `taxon-stats`: `uuid`
- `taxon-year-stats`: `uuid`

## 5. Pagination

List endpoints use limit/offset pagination.

- Query params:
	- `limit` (default `1000`, max `10000`, both configurable)
	- `offset` (zero-based; default `0`)

Paging recommendation for new consumers:

- Begin with a small `limit` during development (for example `limit=100`).
- Use `links.next` to continue rather than calculating URLs manually.

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

Filter syntax is designed to be explicit and readable: `field=value` or `field[operator]=value`.

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

This keeps the public API resource-focused, while still exposing useful relationship filters.

## 7. Sorting

Sorting is supported via:

- `sort=field` ascending
- `sort=-field` descending

Multiple sort fields can be comma-separated:

- `sort=from_date,-unique_key`

If duplicate values occur in the leading sort field, add a stable secondary field
(for example `sort=from_date,unique_key`) for predictable pagination behavior.

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
- `GET /api/v1/taxon-ranks`
- `GET /api/v1/taxon-ranks/{abbr}`
- `GET /api/v1/grid-square-stats`
- `GET /api/v1/grid-square-stats/{uuid}`
- `GET /api/v1/taxon-stats`
- `GET /api/v1/taxon-stats/{uuid}`
- `GET /api/v1/taxon-year-stats`
- `GET /api/v1/taxon-year-stats/{uuid}`

## 12. Non-Goals for v1

- No write operations (`POST`, `PUT`, `PATCH`, `DELETE`) on core data resources
- No direct public endpoints for join tables

## 13. Resource Reference

This section provides a practical reference for each resource with:

- unique identifier field
- exposed fields for response payloads
- filterable fields
- example list queries

All list endpoints also support `limit`, `offset`, and `sort`.

Some endpoints allow joining to other resources in order to enrich the response, filtering and
sorting options with additional fields. To use this feature, provide a parameter `include` with a
comma separated list of supported resource names to include. Field identifiers used in include
extensions take the form `<resource>`__`<fieldname>` where hyphens are replaced with underscore in
the resource name and with 2 underscores separating the resource name from the fieldname. For
endpoints that support include expansions, examples show both:

- base responses (no include)
- enriched responses (with include fields)

Example payloads intentionally reuse a small set of sample identifiers where practical:

- `NHMSYS0021054498` (Bombus terrestris)
- `NHMSYS0021700001` (Andrena hattorfiana)
- `NHMSYS0021900211` (Coccinella septempunctata)

### 13.1 data-sources

- Path: `GET /api/v1/data-sources`
- Item path: `GET /api/v1/data-sources/{abbr}`
- Unique identifier: `abbr`
- Exposed fields: `abbr`, `title`, `url`
- Filterable fields: `abbr`, `title`, `url`

Examples:

- Request: `/api/v1/data-sources?abbr[eq]=NBN`
	Response:

```json
{
	"data": [
		{
			"abbr": "NBN",
			"title": "NBN Atlas",
			"url": "https://nbnatlas.org"
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 1
	},
	"links": {
		"self": "/api/v1/data-sources?abbr[eq]=NBN",
		"next": null,
		"prev": null
	}
}
```

- Request: `/api/v1/data-sources?title[contains]=atlas&sort=title`
	Response:

```json
{
	"data": [
		{
			"abbr": "NBN",
			"title": "NBN Atlas",
			"url": "https://nbnatlas.org"
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 1
	},
	"links": {
		"self": "/api/v1/data-sources?title[contains]=atlas&sort=title",
		"next": null,
		"prev": null
	}
}
```

### 13.2 geographic-regions

- Path: `GET /api/v1/geographic-regions`
- Item path: `GET /api/v1/geographic-regions/{higher_geography_identifier}`
- Unique identifier: `higher_geography_identifier`
- Exposed fields: `higher_geography_identifier`, `higher_geography`, `location_type`
- Filterable fields: `higher_geography_identifier`, `higher_geography`, `location_type`
- include:
  - query parameter: `include`
	- supported values and added fields:
	  - `data-source`:
			- `data_source__abbr`
			- `data_source__title`
			- `data_source__url`

Examples:

- Request: `/api/v1/geographic-regions?include=data-source&higher_geography_identifier[in]=12,13,14`
	Response:

```json
{
	"data": [
		{
			"higher_geography_identifier": 12,
			"higher_geography": "South Hampshire",
			"location_type": "Vice County",
			"data__source_abbr": "IREC",
			"data__source_title": "iRecord",
			"data__source_url": "https://irecord.org.uk"
		},
		{
			"higher_geography_identifier": 13,
			"higher_geography": "North Hampshire",
			"location_type": "Vice County",
			"data__source_abbr": "IREC",
			"data__source_title": "iRecord",
			"data__source_url": "https://irecord.org.uk"
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 2,
		"total": 2
	},
	"links": {
		"self": "/api/v1/geographic-regions?higher_geography_identifier[in]=12,13,14",
		"next": null,
		"prev": null
	}
}
```

- Request: `/api/v1/geographic-regions?higher_geography[contains]=hampshire&sort=higher_geography`
	Response:

```json
{
	"data": [
		{
			"higher_geography_identifier": 13,
			"higher_geography": "North Hampshire",
			"location_type": "Vice County"
		},
		{
			"higher_geography_identifier": 12,
			"higher_geography": "South Hampshire",
			"location_type": "Vice County"
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 2,
		"total": 2
	},
	"links": {
		"self": "/api/v1/geographic-regions?higher_geography[contains]=hampshire&sort=higher_geography",
		"next": null,
		"prev": null
	}
}
```

### 13.3 occurrences

- Path: `GET /api/v1/occurrences`
- Item path: `GET /api/v1/occurrences/{unique_key}`
- Unique identifier: `unique_key`
- Exposed fields:
	- `unique_key`, `taxon_identifier`, `from_date`, `to_date`, `grid_ref`, `grid_ref_2km`,
	- `locality`, `recorded_by`, `identified_by`, `identification_verification_status`
	- `sex`, `life_stage`, `organism_quantity`, `higher_geography_identifier`

	  `higher_geography_identifier` is a helper field used for filtering and sorting and resolves to a
	  single representative region identifier when an occurrence maps to multiple regions.
	- dynamic taxon rank fields by configured rank identifier (for example `kingdom__taxon_identifier`, `family__taxon_identifier`)
- Filterable fields:
	- all exposed occurrence fields above
	- excludes: `blocked`, `blocked_reason`, `created_at`, `updated_at`, `deleted_at`
- Include:
	- query parameter: `include`
	- supported values and added fields:
	  - `data-source`:
			- `data_source__abbr`
			- `data_source__title`
			- `data_source__url`
		- `geographic-region`:
			- `geographic_regions`: array of geographic region objects with:
				- `higher_geography_identifier`
				- `higher_geography`
				- `location_type`
		- `grid-square-stats`:
			- `grid_square_stats__easting`
			- `grid_square_stats__northing`
			- `grid_square_stats__lat`
			- `grid_square_stats__lon`

			Include `grid-square-stats` when the lat/long or easting/northing are required for mapping.
		- `taxon`:
			- `taxon__scientific_name`
			- `taxon__scientific_name_authorship`
			- `taxon__scientific_name_identifier`
			- `taxon__vernacular_name`

			Include `taxon` for additional info about the taxon concept.
		- `taxon-name`
			- `taxon_name__uuid`
			- `taxon_name__name`
			- `taxon_name__given_name_identifier`
			- `taxon_name__accepted`
			- `taxon_name__scientific`

			Include for additional info about the given taxon name.
		- `taxon-rank` -
			- `taxon_rank__rank`
			- `taxon_rank__abbr`
			- `taxon_rank__sort_order`
		- `taxon-group`:
			-	`taxon_group__title`
			-	`taxon_group__friendly`
			-	`taxon_group__external_key`
		- `parent-taxa`:
			- `<rank>__scientific_name`, e.g. `family__scientific_name`
			- `<rank>__vernacular_name`, e.g. `family__vernacular_name`
			Include `parent-taxa` for scientific and vernacular names for the configured the taxonomic
			hierarchy.
	- include-only sort/filter fields are available when their include is requested

Examples:

- Request: `/api/v1/occurrences?include=taxon,taxon-name,taxon-rank,taxon-group,grid-square-stats,geographic-region&taxon_identifier[eq]=NBNORG0021054498`
	Response:

```json
{
	"data": [
		{
			"unique_key": "NBN:123456789",
			"taxon_identifier": "NBNORG0021054498",
			"from_date": "2024-05-11",
			"to_date": "2024-05-11",
			"grid_ref": "SU123456",
			"grid_ref_2km": "SU15A",
			"locality": "Titchfield Haven",
			"recorded_by": "J. Smith",
			"identified_by": "A. Brown",
			"identification_verification_status": "V",
			"sex": "female",
			"life_stage": "adult",
			"organism_quantity": "1",
			"higher_geography_identifier": 13,
			"geographic_regions": [
				{
					"higher_geography_identifier": 13,
					"higher_geography": "North Hampshire",
					"location_type": "Vice County"
				}
			],
			"grid_square_stats__easting": 410000,
			"grid_square_stats__northing": 110000,
			"grid_square_stats__lat": 52.8261,
			"grid_square_stats__lon": 1.4458,
			"taxon__scientific_name": "Bombus terrestris",
			"taxon__scientific_name_authorship": "L.",
			"taxon__scientific_name_identifier": "NHMSYS0073AC441A",
			"taxon__vernacular_name": "Buff-tailed Bumblebee",
			"taxon_name__uuid": "3d77f8e7-e2e8-4d74-9d4d-cff4d11130e8",
			"taxon_name__name": "Buff-tailed Bumblebee",
			"taxon_name__given_name_identifier": "9876FGH",
			"taxon_name__accepted": 0,
			"taxon_name__scientific": 0,
			"taxon_rank__rank": "Species",
			"taxon_rank__abbr": "sp",
			"taxon_rank__sort_order": 300,
			"taxon_group__title": "insect - hymenoptera",
			"taxon_group__friendly": "Bees, Wasps and Ants",
			"taxon_group__external_key": "ABC123"
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 57
	},
	"links": {
		"self": "/api/v1/occurrences?include=taxon,taxon-name,taxon-rank,taxon-group&taxon_identifier[eq]=NBNORG0021054498",
		"next": "/api/v1/occurrences?include=taxon,taxon-name,taxon-rank,taxon-group&taxon_identifier[eq]=NBNORG0021054498&limit=1000&offset=1000",
		"prev": null
	}
}
```

- Request: `/api/v1/occurrences?higher_geography_identifier[eq]=13&from_date[gte]=2020-01-01&to_date[lte]=2024-12-31`
	Response:

```json
{
	"data": [
		{
			"unique_key": "iRecord:998877",
			"taxon_identifier": "NHMSYS0021700001",
			"from_date": "2022-07-03",
			"to_date": "2022-07-03",
			"grid_ref": "SU441100",
			"grid_ref_2km": "SU41F",
			"locality": "Winchester Meadows",
			"recorded_by": "L. Patel",
			"identified_by": "L. Patel",
			"identification_verification_status": "C",
			"sex": null,
			"life_stage": "larva",
			"organism_quantity": "3",
			"higher_geography_identifier": 13
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 812
	},
	"links": {
		"self": "/api/v1/occurrences?higher_geography_identifier[eq]=13&from_date[gte]=2020-01-01&to_date[lte]=2024-12-31",
		"next": "/api/v1/occurrences?higher_geography_identifier[eq]=13&from_date[gte]=2020-01-01&to_date[lte]=2024-12-31&limit=1000&offset=1000",
		"prev": null
	}
}
```

- Request: `/api/v1/occurrences?recorded_by[contains]=smith&sort=-from_date`
	Response:

```json
{
	"data": [
		{
			"unique_key": "NBN:77001122",
			"taxon_identifier": "NHMSYS0021900211",
			"from_date": "2025-08-19",
			"to_date": "2025-08-19",
			"grid_ref": "SU221870",
			"grid_ref_2km": "SU28B",
			"locality": "Romsey Common",
			"recorded_by": "J. Smith",
			"identified_by": "P. Clarke",
			"identification_verification_status": "V",
			"sex": "male",
			"life_stage": "adult",
			"organism_quantity": "2",
			"higher_geography_identifier": 12
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 146
	},
	"links": {
		"self": "/api/v1/occurrences?recorded_by[contains]=smith&sort=-from_date",
		"next": null,
		"prev": null
	}
}
```

### 13.4 recording-schemes

- Path: `GET /api/v1/recording-schemes`
- Item path: `GET /api/v1/recording-schemes/{external_key}`
- Unique identifier: `external_key`
- Exposed fields: `external_key`, `title`
- Filterable fields: `external_key`, `title`

Examples:

- Request: `/api/v1/recording-schemes?external_key[eq]=ABCD1234EFGH5678`
	Response:

```json
{
	"data": [
		{
			"external_key": "ABCD1234EFGH5678",
			"title": "Hampshire Moth Scheme"
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 1
	},
	"links": {
		"self": "/api/v1/recording-schemes?external_key[eq]=ABCD1234EFGH5678",
		"next": null,
		"prev": null
	}
}
```

- Request: `/api/v1/recording-schemes?title[contains]=moth&sort=title`
	Response:

```json
{
	"data": [
		{
			"external_key": "ABCD1234EFGH5678",
			"title": "Hampshire Moth Scheme"
		},
		{
			"external_key": "MNOP1234QRST5678",
			"title": "National Moth Recording Scheme"
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 2,
		"total": 2
	},
	"links": {
		"self": "/api/v1/recording-schemes?title[contains]=moth&sort=title",
		"next": null,
		"prev": null
	}
}
```

### 13.5 taxa

- Path: `GET /api/v1/taxa`
- Item path: `GET /api/v1/taxa/{taxon_identifier}`
- Unique identifier: `taxon_identifier`
- Exposed fields:
	- `taxon_identifier`, `scientific_name_identifier`, `scientific_name`,
	  `scientific_name_authorship`, `vernacular_name`, `id_difficulty`,
		`conservation_status`, `taxon_remarks`, `rarity_group_name`
	- dynamic taxon rank fields by configured rank identifier
- Filterable fields:
	- all exposed taxa fields above
	- excludes: `blocked`, `blocked_reason`, `created_at`, `updated_at`, `deleted_at`
- Include:
	- query parameter: `include`
	- supported values and added fields:
		- `recording-scheme`:
			- `recording_scheme__external_key`
			- `recording_scheme__title`
		- `taxon-group`:
			-	`taxon_group__title`
			-	`taxon_group__friendly`
			-	`taxon_group__external_key`
		- `taxon-rank` -
			- `taxon_rank__rank`
			- `taxon_rank__abbr`
			- `taxon_rank__sort_order`
		- `parent-taxa`:
			- `<rank>__scientific_name`, e.g. `family__scientific_name`
			- `<rank>__vernacular_name`, e.g. `family__vernacular_name`

			Include `parent-taxa` for scientific and vernacular names for the configured the taxonomic
			hierarchy.
	- include-only sort/filter fields are available when their include is requested

Examples:

- Request: `/api/v1/taxa?scientific_name[contains]=bombus`
	Response:

```json
{
	"data": [
		{
			"taxon_identifier": "NHMSYS0021054498",
			"scientific_name_identifier": "TVK-001",
			"scientific_name": "Bombus terrestris",
			"scientific_name_authorship": "Linnaeus, 1758",
			"vernacular_name": "Buff-tailed Bumblebee",
			"taxon_group_external_key": "hymenoptera",
			"id_difficulty": 2,
			"recording_scheme_external_key": "ABCD1234EFGH5678",
			"conservation_status": "LC",
			"taxon_remarks": "Common and widespread.",
			"rarity_group_name": "common"
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 24
	},
	"links": {
		"self": "/api/v1/taxa?scientific_name[contains]=bombus",
		"next": null,
		"prev": null
	}
}
```

- Request: `/api/v1/taxa?conservation_status[in]=VU,EN,CR&sort=scientific_name`
	Response:

```json
{
	"data": [
		{
			"taxon_identifier": "NHMSYS0021700001",
			"scientific_name_identifier": "TVK-8821",
			"scientific_name": "Andrena hattorfiana",
			"scientific_name_authorship": "Fabricius, 1775",
			"vernacular_name": "Large Scabious Mining Bee",
			"taxon_group_external_key": "hymenoptera",
			"id_difficulty": 4,
			"recording_scheme_external_key": "ABCD1234EFGH5678",
			"conservation_status": "EN",
			"taxon_remarks": "Declining species.",
			"rarity_group_name": "scarce"
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 63
	},
	"links": {
		"self": "/api/v1/taxa?conservation_status[in]=VU,EN,CR&sort=scientific_name",
		"next": null,
		"prev": null
	}
}
```

- Request: `/api/v1/taxa?taxon_group_external_key[eq]=beetles`
	Response:

```json
{
	"data": [
		{
			"taxon_identifier": "NHMSYS0021900211",
			"scientific_name_identifier": "TVK-12021",
			"scientific_name": "Coccinella septempunctata",
			"scientific_name_authorship": "Linnaeus, 1758",
			"vernacular_name": "Seven-spot Ladybird",
			"taxon_group_external_key": "beetles",
			"id_difficulty": 1,
			"recording_scheme_external_key": "ABCD1234EFGH5678",
			"conservation_status": "LC",
			"taxon_remarks": null,
			"rarity_group_name": "common"
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 425
	},
	"links": {
		"self": "/api/v1/taxa?taxon_group_external_key[eq]=beetles",
		"next": null,
		"prev": null
	}
}
```

### 13.6 taxon-groups

- Path: `GET /api/v1/taxon-groups`
- Item path: `GET /api/v1/taxon-groups/{external_key}`
- Unique identifier: `external_key`
- Exposed fields: `external_key`, `title`, `friendly`, `indicia_taxon_group_id`, `implied`
- Filterable fields: `external_key`, `title`, `friendly`, `indicia_taxon_group_id`, `implied`

Examples:

- Request: `/api/v1/taxon-groups?friendly[contains]=dragon`
	Response:

```json
{
	"data": [
		{
			"external_key": "odonata",
			"title": "Odonata",
			"friendly": "Dragonflies and Damselflies",
			"indicia_taxon_group_id": 7
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 1
	},
	"links": {
		"self": "/api/v1/taxon-groups?friendly[contains]=dragon",
		"next": null,
		"prev": null
	}
}
```

- Request: `/api/v1/taxon-groups?indicia_taxon_group_id[eq]=7`
	Response:

```json
{
	"data": [
		{
			"external_key": "odonata",
			"title": "Odonata",
			"friendly": "Dragonflies and Damselflies",
			"indicia_taxon_group_id": 7
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 1
	},
	"links": {
		"self": "/api/v1/taxon-groups?indicia_taxon_group_id[eq]=7",
		"next": null,
		"prev": null
	}
}
```

### 13.7 taxon-names

- Path: `GET /api/v1/taxon-names`
- Item path: `GET /api/v1/taxon-names/{uuid}`
- Unique identifier: `uuid`
- Exposed fields: `uuid`, `taxon_identifier`, `given_name_identifier`, `name`,  `accepted`, `scientific`
- Filterable fields: `uuid`, `taxon_identifier`, `given_name_identifier`, `name`, `accepted`, `scientific`
- Include:
	- query parameter: `include`
	- supported values and added fields:
		- `taxon`:
			- `taxon__scientific_name`
			- `taxon__scientific_name_authorship`
			- `taxon__scientific_name_identifier`
			- `taxon__vernacular_name`

			Include `taxon` for additional info about the taxon concept.
		- `taxon-rank`:
			- `taxon_rank__rank`
			- `taxon_rank__abbr`
			- `taxon_rank__sort_order`
		- `taxon-group`:
			-	`taxon_group__title`
			-	`taxon_group__friendly`
			-	`taxon_group__external_key`
		- `parent-taxa`:
			- `<rank>__scientific_name`, e.g. `family__scientific_name`
			- `<rank>__vernacular_name`, e.g. `family__vernacular_name`

Examples:

- Request: `/api/v1/taxon-names?name[contains]=robin`
	Response:

```json
{
	"data": [
		{
			"uuid": "96fd5b7a-7ed8-4f2f-a84b-aec6fbfa632f",
			"taxon_identifier": "NHMSYS0022000100",
			"given_name_identifier": "TVK-ROBIN-01",
			"name": "European Robin",
			"accepted": 1,
			"scientific": 0
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 3
	},
	"links": {
		"self": "/api/v1/taxon-names?name[contains]=robin",
		"next": null,
		"prev": null
	}
}
```

- Request: `/api/v1/taxon-names?accepted[eq]=1&scientific[eq]=0`
	Response:

```json
{
	"data": [
		{
			"uuid": "96fd5b7a-7ed8-4f2f-a84b-aec6fbfa632f",
			"taxon_identifier": "NHMSYS0022000100",
			"given_name_identifier": "TVK-ROBIN-01",
			"name": "European Robin",
			"accepted": 1,
			"scientific": 0
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 8124
	},
	"links": {
		"self": "/api/v1/taxon-names?accepted[eq]=1&scientific[eq]=0",
		"next": "/api/v1/taxon-names?accepted[eq]=1&scientific[eq]=0&limit=1000&offset=1000",
		"prev": null
	}
}
```

- Request: `/api/v1/taxon-names?taxon_identifier[eq]=NBNORG0021054498`
	Response:

```json
{
	"data": [
		{
			"uuid": "3d77f8e7-e2e8-4d74-9d4d-cff4d11130e8",
			"taxon_identifier": "NBNORG0021054498",
			"name": "Bombus terrestris",
			"scientific_name_identifier": "TVK-001",
			"accepted": 1,
			"scientific": 1
		},
		{
			"uuid": "f54da6a0-5f0b-4de2-a10a-2693b193f5f2",
			"taxon_identifier": "NBNORG0021054498",
			"name": "Buff-tailed Bumblebee",
			"scientific_name_identifier": "TVK-001",
			"accepted": 1,
			"scientific": 0
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 2,
		"total": 2
	},
	"links": {
		"self": "/api/v1/taxon-names?taxon_identifier[eq]=NBNORG0021054498",
		"next": null,
		"prev": null
	}
}
```

### 13.8 taxon-ranks

- Path: `GET /api/v1/taxon-ranks`
- Item path: `GET /api/v1/taxon-ranks/{abbr}`
- Unique identifier: `abbr`
- Exposed fields: `rank`, `abbr`, `sort_order`
- Filterable fields: `rank`, `abbr`, `sort_order`

Examples:

- Request: `/api/v1/taxon-ranks?abbr[contains]=sp`
	Response:

```json
{
	"data": [
		{
			"rank": "Species",
			"abbr": "sp",
			"sort_order": 300
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 1
	},
	"links": {
		"self": "/api/v1/taxon-ranks?abbr[contains]=sp",
		"next": null,
		"prev": null
	}
}
```

### 13.9 grid-square-stats

- Path: `GET /api/v1/grid-square-stats`
- Item path: `GET /api/v1/grid-square-stats/{uuid}`
- Unique identifier: `uuid`
- Exposed fields: `uuid`, `square`, `easting`, `northing`, `lon`, `lat`, `partial`,
  `occurrences_count`, `species_count`, `higher_geography_identifier`
- Filterable fields: `uuid`, `square`, `easting`, `northing`, `lon`, `lat`, `partial`,
  `occurrences_count`, `species_count`, `higher_geography_identifier`
- Include:
	- query parameter: `include`
	- supported values and added fields:
		- `geographic-region`:
			- `geographic_region__higher_geography`
			-	`geographic_region__location_type`
	- include-only sort/filter fields are available when `include=geographic-region`

Examples:

- Request: `/api/v1/grid-square-stats?include=geographic-region&partial[eq]=0`
	Response:

```json
{
	"data": [
		{
			"uuid": "c5b2d8f0-c8bb-4b03-9be8-f1a6ea02cfd8",
			"square": "SU41F",
			"easting": 441000,
			"northing": 110000,
			"lon": -1.9343,
			"lat": 54.241,
			"higher_geography_identifier": null,
			"partial": 0,
			"occurrences_count": 122,
			"species_count": 84,
			"geographic_region__higher_geography": "South Hampshire",
			"geographic_region__location_type": "Vice County"
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 628
	},
	"links": {
		"self": "/api/v1/grid-square-stats?include=geographic-region&partial[eq]=0",
		"next": null,
		"prev": null
	}
}
```

- Request: `/api/v1/grid-square-stats?higher_geography_identifier[eq]=13&species_count[gte]=50&sort=-species_count`
	Response:

```json
{
	"data": [
		{
			"uuid": "8f1af0f3-5140-4ee4-bce5-6efe8b33731c",
			"square": "SU38K",
			"easting": 438000,
			"northing": 108000,
			"lon": -1.5253,
			"lat": 54.0982,
			"higher_geography_identifier": 13,
			"partial": 1,
			"occurrences_count": 201,
			"species_count": 133
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 47
	},
	"links": {
		"self": "/api/v1/grid-square-stats?higher_geography_identifier[eq]=13&species_count[gte]=50&sort=-species_count",
		"next": null,
		"prev": null
	}
}
```

### 13.10 taxon-stats

- Path: `GET /api/v1/taxon-stats`
- Item path: `GET /api/v1/taxon-stats/{uuid}`
- Unique identifier: `uuid`
- Exposed fields:
	- `uuid`, `taxon_identifier`, `higher_geography_identifier`,
	  `occurrences_count`, `grid_square_count`, `first_record_date`, `last_record_date`,
		`first_recorder`, `last_recorder`, `first_verified_record_date`, `last_verified_record_date`,
		`first_verified_recorder`, `last_verified_recorder`
- Filterable fields:
	- all exposed taxon-stats fields above
	- rows for blocked taxa are always excluded
- Include:
	- query parameter: `include`
	- supported values and added fields:
		- `geographic-region`:
			- `geographic_region__higher_geography`
			-	`geographic_region__location_type`
		- `taxon`:
			- `taxon__scientific_name`
			- `taxon__scientific_name_authorship`
			- `taxon__scientific_name_identifier`
			- `taxon__vernacular_name`

			Include `taxon` for additional info about the taxon concept.
		- `taxon-rank` -
			- `taxon_rank__rank`
			- `taxon_rank__abbr`
			- `taxon_rank__sort_order`

			`taxon-rank` is only available if taxon included.
		- `taxon-group`:
			-	`taxon_group__title`
			-	`taxon_group__friendly`
			-	`taxon_group__external_key`

			`taxon-group` is only available if taxon included.
		- `parent-taxa`:
			- `<rank>__scientific_name`, e.g. `family__scientific_name`
			- `<rank>__vernacular_name`, e.g. `family__vernacular_name`

			Include `parent-taxa` for scientific and vernacular names for the configured the taxonomic
			hierarchy. `parent-taxa` is only available if taxon included.
	- include-only sort/filter fields are available when their include is requested

Examples:

- Request: `/api/v1/taxon-stats?include=taxon,taxon-rank,taxon-group,geographic-region&taxon_identifier[eq]=NBNORG0021054498`
	Response:

```json
{
	"data": [
		{
			"uuid": "f1b02df6-6db5-4d0d-b277-7e54b08a4f1c",
			"taxon_identifier": "NBNORG0021054498",
			"higher_geography_identifier": null,
			"occurrences_count": 374,
			"grid_square_count": 119,
			"first_record_date": "1987-06-19",
			"last_record_date": "2025-09-03",
			"first_recorder": "J. Winter",
			"last_recorder": "R. Hall",
			"first_verified_record_date": "1988-05-14",
			"last_verified_record_date": "2025-09-03",
			"first_verified_recorder": "J. Winter",
			"last_verified_recorder": "R. Hall",
			"geographic_region__higher_geography": "South Hampshire",
			"geographic_region__location_type": "Vice County",
			"taxon__scientific_name": "Bombus terrestris",
			"taxon__scientific_name_authorship": "L.",
			"taxon__scientific_name_identifier": "XYZ123",
			"taxon__vernacular_name": "Buff-tailed Bumblebee",
			"taxon_rank__rank": "Species",
			"taxon_rank__abbr": "sp",
			"taxon_rank__sort_order": 300,
			"taxon_group__title": "insect - hymenoptera",
			"taxon_group__friendly": "Bees, Wasps and Ants",
			"taxon_group__external_key": "ABC123"
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 1
	},
	"links": {
		"self": "/api/v1/taxon-stats?include=taxon,taxon-rank,taxon-group,geographic-region&taxon_identifier[eq]=NBNORG0021054498",
		"next": null,
		"prev": null
	}
}
```

- Request: `/api/v1/taxon-stats?higher_geography_identifier[eq]=13&occurrences_count[gte]=10&sort=-occurrences_count`
	Response:

```json
{
	"data": [
		{
			"uuid": "29dd365b-4c63-4f11-ac1f-2d53131d402e",
			"taxon_identifier": "NBNORG0021054498",
			"higher_geography_identifier": 13,
			"occurrences_count": 118,
			"grid_square_count": 34,
			"first_record_date": "1999-04-12",
			"last_record_date": "2025-08-18",
			"first_recorder": "D. Evans",
			"last_recorder": "M. Green",
			"first_verified_record_date": "2001-06-01",
			"last_verified_record_date": "2025-08-18",
			"first_verified_recorder": "D. Evans",
			"last_verified_recorder": "M. Green"
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 91
	},
	"links": {
		"self": "/api/v1/taxon-stats?higher_geography_identifier[eq]=13&occurrences_count[gte]=10&sort=-occurrences_count",
		"next": null,
		"prev": null
	}
}
```

### 13.11 taxon-year-stats

- Path: `GET /api/v1/taxon-year-stats`
- Item path: `GET /api/v1/taxon-year-stats/{uuid}`
- Unique identifier: `uuid`
- Exposed fields: `uuid`, `taxon_identifier`, `higher_geography_identifier`, `year`, `occurrences_count`, `grid_square_count`
- Filterable fields:
	- `uuid`, `taxon_identifier`, `higher_geography_identifier`, `year`, `occurrences_count`, `grid_square_count`
	- rows for blocked taxa are always excluded
- Include:
	- query parameter: `include`
	- supported values and added fields:
		- `geographic-region`:
			- `geographic_region__higher_geography`
			-	`geographic_region__location_type`
		- `taxon`:
			- `taxon__scientific_name`
			- `taxon__scientific_name_authorship`
			- `taxon__scientific_name_identifier`
			- `taxon__vernacular_name`

			Include `taxon` for additional info about the taxon concept.
		- `taxon-rank` -
			- `taxon_rank__rank`
			- `taxon_rank__abbr`
			- `taxon_rank__sort_order`

			`taxon-rank` is only available if taxon included.
		- `taxon-group`:
			-	`taxon_group__title`
			-	`taxon_group__friendly`
			-	`taxon_group__external_key`

			`taxon-group` is only available if taxon included.
		- `parent-taxa`:
			- `<rank>__scientific_name`, e.g. `family__scientific_name`
			- `<rank>__vernacular_name`, e.g. `family__vernacular_name`

			Include `parent-taxa` for scientific and vernacular names for the configured the taxonomic
			hierarchy.
	- include-only sort/filter fields are available when their include is requested

Examples:

- Request: `/api/v1/taxon-year-stats?include=taxon,taxon-rank,taxon-group,geographic-region&taxon_identifier[eq]=NBNORG0021054498&year[gte]=2016`
	Response:

```json
{
	"data": [
		{
			"uuid": "f4eecddf-c532-4f66-b1b8-3d4245e3b478",
			"taxon_identifier": "NBNORG0021054498",
			"higher_geography_identifier": null,
			"year": 2024,
			"occurrences_count": 42,
			"grid_square_count": 18,
			"geographic_region__higher_geography": "South Hampshire",
			"geographic_region__location_type": "Vice County",
			"taxon__scientific_name": "Bombus terrestris",
			"taxon__scientific_name_authorship": "L.",
			"taxon__scientific_name_identifier": "XYZ123",
			"taxon__vernacular_name": "Buff-tailed Bumblebee",
			"taxon_rank__rank": "Species",
			"taxon_rank__abbr": "sp",
			"taxon_rank__sort_order": 300,
			"taxon_group__title": "insect - hymenoptera",
			"taxon_group__friendly": "Bees, Wasps and Ants",
			"taxon_group__external_key": "ABC123"
		},
		{
			"uuid": "0d4f09bb-b2b2-4522-a80c-82169f4989c4",
			"taxon_identifier": "NBNORG0021054498",
			"higher_geography_identifier": null,
			"year": 2025,
			"occurrences_count": 39,
			"grid_square_count": 16,
			"geographic_region__higher_geography": "South Hampshire",
			"geographic_region__location_type": "Vice County",
			"taxon__scientific_name": "Bombus terrestris",
			"taxon__scientific_name_authorship": "L.",
			"taxon__scientific_name_identifier": "XYZ123",
			"taxon__vernacular_name": "Buff-tailed Bumblebee",
			"taxon_rank__rank": "Species",
			"taxon_rank__abbr": "sp",
			"taxon_rank__sort_order": 300,
			"taxon_group__title": "insect - hymenoptera",
			"taxon_group__friendly": "Bees, Wasps and Ants",
			"taxon_group__external_key": "ABC123"
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 2,
		"total": 10
	},
	"links": {
		"self": "/api/v1/taxon-year-stats?include=taxon,taxon-rank,taxon-group,geographic-region&taxon_identifier[eq]=NBNORG0021054498&year[gte]=2016",
		"next": null,
		"prev": null
	}
}
```

- Request: `/api/v1/taxon-year-stats?higher_geography_identifier[eq]=13&year[eq]=2025&sort=-occurrences_count`
	Response:

```json
{
	"data": [
		{
			"uuid": "f32f208d-45af-44da-9bb6-5a26f61a95c8",
			"taxon_identifier": "NHMSYS0021700001",
			"higher_geography_identifier": 13,
			"year": 2025,
			"occurrences_count": 26,
			"grid_square_count": 12
		}
	],
	"meta": {
		"limit": 1000,
		"offset": 0,
		"count": 1,
		"total": 280
	},
	"links": {
		"self": "/api/v1/taxon-year-stats?higher_geography_identifier[eq]=13&year[eq]=2025&sort=-occurrences_count",
		"next": null,
		"prev": null
	}
}
```

## 14. Implementation Notes for Developers

- Prefer exposing relationship identifiers that are stable API keys (for example `taxon_identifier`, `external_key`, `abbr`) rather than internal numeric IDs.
- Validate filter fields per resource and return RFC 9457 problem responses for unsupported filters.
- Apply case-insensitive behavior for all `contains` filters.
- Ensure blocked-record exclusion is applied consistently before pagination totals are calculated.

## 15. OpenAPI Starter

An OpenAPI 3.1 starter specification is provided in `docs/openapi.v1.yaml`.

- Use it as the implementation contract for endpoint shape, security, and common response envelopes.
- Expand resource schemas and examples as implementation progresses.