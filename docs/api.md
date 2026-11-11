# Tanhub REST API (v1)

## 1. Overview

Tanhub exposes a read-only REST API for discovering taxa, retrieving biological records
(occurrences), and exploring summary statistics. You can make your first request without an
account. Optional authentication gives you a separate, configurable rate-limit allowance; it does
not unlock additional data.

- Base path: `/api/v1` on your Tanhub installation.
- Format: JSON for successful responses; `application/problem+json` for API errors.
- Data access: `GET` for lists and individual resources.
- Authentication: optional bearer tokens, with separate `POST` endpoints for token management.

This is the canonical human-readable guide to API behaviour. The
[OpenAPI v1 specification](openapi.v1.yaml) describes the machine-readable contract.

### Find your way around

- [Quick start](#quick-start)
- [Authentication](#2-authentication-and-authorization) and [rate limits](#3-rate-limiting)
- [Resources and identifiers](#41-resources-and-unique-identifiers)
- [Related data with include](#42-including-related-data)
- [Reporting taxa and taxonomy](#43-reporting-taxa-and-parent-relationships)
- [Pagination](#5-pagination), [filtering](#6-filtering), and [sorting](#7-sorting)
- [Configurable taxon attributes](#65-configurable-taxon-attributes)
- [Errors](#10-error-handling)
- [Endpoint summary](#11-endpoint-summary) and [resource reference](#13-resource-reference)

### Quick start

Replace `https://tanhub.example` with the address of your installation:

```sh
curl --get 'https://tanhub.example/api/v1/taxa' \
  --data-urlencode 'scientific_name[contains]=bombus' \
  --data-urlencode 'include=taxon-group,taxon-attributes' \
  --data-urlencode 'sort=scientific_name,taxon_identifier' \
  --data-urlencode 'limit=10'
```

The response contains a `data` array of matching taxa, pagination metadata, and navigation links.
`taxon-group` adds group details; `taxon-attributes` adds any deployment-specific attributes.
An installation with no configured attributes returns an empty `taxon_attributes` object.

For a typical integration:

1. Choose a resource from the [resource directory](#41-resources-and-unique-identifiers).
2. Add only the related information you need with `include`.
3. Filter and sort the list, using a small `limit` while developing.
4. Follow `links.next` until it is `null`.
5. Keep the public identifier if you need to fetch an individual record later.

Query examples below use readable, unescaped parameter names. Use your HTTP client's query-string
encoder for brackets, spaces, semicolons, and other special characters. The `curl --data-urlencode`
form above handles this for you. Sample identifiers and values are illustrative; discover the
values available on your installation rather than relying on these examples being present.

## 2. Authentication and Authorization

All data `GET` endpoints allow anonymous access. A valid access token selects the authenticated
rate-limit allowance, normally higher than the anonymous allowance. It does not change resource
visibility or bypass blocking rules.

Tokens are opaque CodeIgniter Shield bearer tokens, not JWTs. Store them securely, use HTTPS, and
do not put them in query strings or try to decode them.

### 2.1 Token endpoints

Token-management requests use `Content-Type: application/json`.

#### Obtain tokens

Send `POST /api/v1/auth/token` with your account email address in `username`:

```json
{
  "username": "api-user@example.org",
  "password": "<your-password>"
}
```

A successful request returns HTTP 200:

```json
{
  "access_token": "<access-token>",
  "token_type": "Bearer",
  "expires_in": 3600,
  "refresh_token": "<refresh-token>"
}
```

The access token lasts one hour (`expires_in` is in seconds). The refresh token lasts 30 days.
Missing required credentials return HTTP 400; incorrect credentials return HTTP 401.

#### Refresh tokens

Send `POST /api/v1/auth/token/refresh`:

```json
{
  "refresh_token": "<refresh-token>"
}
```

The response has the same shape as the token response above. Refreshing revokes the submitted
refresh token and issues a new pair, so save the new refresh token before the next refresh.
A missing refresh token returns HTTP 400; an invalid, expired, or already-used refresh token
returns HTTP 401.

#### Revoke tokens

Send `POST /api/v1/auth/token/revoke` with either or both tokens:

```json
{
  "access_token": "<access-token>",
  "refresh_token": "<refresh-token>"
}
```

If neither field is supplied, the endpoint revokes the bearer token in the `Authorization`
header instead. The response is HTTP 204 with no body, including for unknown or already-revoked
tokens. Revoke both tokens when you want to discard the whole pair.

### 2.2 Bearer token usage

Attach the access token to data requests:

```http
Authorization: Bearer <access-token>
```

An absent or invalid bearer token on a data request uses the anonymous rate-limit allowance;
it does not make the public data endpoint return HTTP 401. Token issuance and refresh can return
401 as described above.

## 3. Rate Limiting

Rate limits are configured per installation. The defaults are 20 requests per 20 seconds for
anonymous clients and 60 requests per 20 seconds for authenticated clients. Allowances are keyed
by request path and identity: IP address for anonymous requests, user account for authenticated
requests. Different tokens for the same account share that account's allowance on a given path.
Token-management endpoints are also rate limited.

Responses include:

- `X-RateLimit-Limit`: the configured capacity.
- `X-RateLimit-Reset`: a duration in seconds, not a Unix timestamp. Allowed requests report the
  configured period; throttled requests report the retry delay.

When throttled, the API returns HTTP `429 Too Many Requests`, an `application/problem+json`
body, and a `Retry-After` header containing the number of seconds to wait. Honour that delay
before retrying rather than repeatedly sending the same request.

See [API access configuration](configuration-reference.md#api-access) for installation settings.
Browser clients also need an allowed origin; see
[cross-origin configuration](configuration-reference.md#cross-origin-requests).

## 4. Resource Model

Most resources have two endpoints:

- List: `GET /api/v1/<resource>`
- Individual resource: `GET /api/v1/<resource>/{identifier}`

Use the public identifiers below rather than internal database primary keys. They are intended
for client references across imports and database rebuilds. URL-encode identifiers when inserting
them into a path segment.

### 4.1 Resources and unique identifiers

The directory below explains what each resource represents and the identifier for its item
endpoint. All paths are relative to `/api/v1`.

| Resource | What it provides | Item identifier |
| --- | --- | --- |
| `data-sources` | Originating datasets or systems | `abbr` |
| `geographic-regions` | Named reporting areas, such as vice-counties | `higher_geography_identifier` |
| `occurrences` | Individual biological records | `unique_key` |
| `recording-schemes` | Recording organisations or schemes | `external_key` |
| `taxa` | Accepted taxon concepts and their properties | `taxon_identifier` |
| `taxon-groups` | Broad taxonomic groups | `external_key` |
| `taxon-names` | Scientific and vernacular names associated with taxa | `uuid` |
| `taxon-ranks` | Taxonomic ranks and reporting flags | `abbr` |
| `grid-square-stats` | Counts and rarity scores for mapped squares | `uuid` |
| `taxon-stats` | Taxon statistics and expert decisions, globally or by region | `uuid` |
| `taxon-year-stats` | Taxon counts by year, globally or by region | `uuid` |
| `taxon-attributes` | Enabled, deployment-specific attribute definitions | `key` |
| `taxon-attribute-terms` | Currently used controlled terms for enabled attributes | List only; no item endpoint |

Term identity is the combination of `attribute_key` and `key`, not a term key alone.

### 4.2 Including related data

Use `include` with a comma-separated list to add related information to either a list or an item:

```text
/api/v1/taxa/NHMSYS0021054498?include=taxon-group,taxon-attributes
/api/v1/occurrences?include=taxon,taxon-media,grid-square-stats&limit=10
```

Most included fields are flattened using two underscores between the related resource name and
field name. Hyphens in resource names become underscores:

- `include=taxon` adds fields such as `taxon__scientific_name`.
- `include=data-source` adds `data_source__abbr`, not `data__source_abbr`.

Some expansions instead add structured data: `taxon_media` and `geographic_regions` are arrays;
`taxon_attributes` is an object of attribute arrays.

#### Include availability

This table lists every supported include. A value in the final column must be requested alongside
`taxon`; requesting it alone returns HTTP 400. Resources not listed do not support includes.

| Resource | Includes available directly | Additional includes requiring `taxon` |
| --- | --- | --- |
| `geographic-regions` | `data-source` | None |
| `occurrences` | `data-source`, `geographic-region`, `grid-square-stats`, `taxon`, `taxon-name` | `taxon-media`, `taxon-rank`, `taxon-group`, `parent-taxon`, `parent-taxa`, `taxon-attributes` |
| `taxa` | `taxon-media`, `recording-scheme`, `taxon-group`, `taxon-rank`, `parent-taxon`, `parent-taxa`, `taxon-attributes` | None |
| `taxon-names` | `taxon`, `taxon-media`, `taxon-group`, `taxon-rank`, `parent-taxon`, `parent-taxa` | `taxon-attributes` |
| `grid-square-stats` | `geographic-region` | None |
| `taxon-stats`, `taxon-year-stats` | `geographic-region`, `taxon` | `taxon-media`, `taxon-rank`, `taxon-group`, `parent-taxon`, `parent-taxa`, `taxon-attributes` |

In particular, taxon names can include media, ranks, groups, or parents without `taxon`, whereas
occurrences and statistics require it for those expansions.

#### Included field reference

The following lists define the added fields wherever an include is supported:

- `data-source`: `data_source__abbr`, `data_source__title`, `data_source__url`.
- `recording-scheme`: `recording_scheme__external_key`, `recording_scheme__title`.
- `taxon`: `taxon__scientific_name`, `taxon__scientific_name_authorship`,
  `taxon__scientific_name_identifier`, `taxon__vernacular_name`, `taxon__id_difficulty`,
  `taxon__gb_red_list`, `taxon__gb_status`, `taxon__rarity_category`,
  `taxon__exclude_from_rarity_calculations`, and
  `taxon__exclude_from_rarity_calculations_comment`.
- `taxon-name`: `taxon_name__uuid`, `taxon_name__name`, `taxon_name__given_name_identifier`,
  `taxon_name__accepted`, `taxon_name__scientific`.
- `taxon-group`: `taxon_group__external_key`, `taxon_group__title`, `taxon_group__friendly`.
- `taxon-rank`: `taxon_rank__rank`, `taxon_rank__abbr`, `taxon_rank__sort_order`. On taxa, taxon
  names, taxon stats, and yearly taxon stats, it also adds `taxon_rank__is_reporting`.
  Occurrences do not expose that extra field.
- `grid-square-stats`: `grid_square_stats__easting`, `grid_square_stats__northing`,
  `grid_square_stats__lat`, `grid_square_stats__lon`. Use these for occurrence mapping.
- `geographic-region`: on occurrences, adds `geographic_regions`, an array of objects containing
  `higher_geography_identifier`, `higher_geography`, and `location_type`. On square and taxon
  statistics, instead adds `geographic_region__higher_geography` and
  `geographic_region__location_type`.
- `parent-taxon`: `parent_taxon__taxon_identifier`, `parent_taxon__scientific_name`,
  `parent_taxon__vernacular_name`, `parent_taxon__rank`, `parent_taxon__rank_abbr`.
- `parent-taxa`: `<rank>__scientific_name` and `<rank>__vernacular_name` for configured reporting
  ranks, for example `family__scientific_name`. Rank aliases are lower-case, with punctuation
  normalised to underscores; available aliases depend on the installation.
- `taxon-media`: `taxon_media`, an array described in the [taxa reference](#taxon-media).
- `taxon-attributes`: `taxon_attributes`, an object described in
  [configurable taxon attributes](#65-configurable-taxon-attributes).

Included scalar fields can be filtered and sorted only when their include is requested:

```text
/api/v1/occurrences?include=taxon&taxon__scientific_name[contains]=bombus&sort=taxon__scientific_name,unique_key
```

Nested array/object members are not ordinary filter or sort fields. For example, use the
occurrence `higher_geography_identifier` convenience filter for region membership and
`attribute__<key>` for configurable attributes, rather than filtering nested JSON paths.

### 4.3 Reporting taxa and parent relationships

A taxon is a taxonomic concept, such as a species or subspecies. A taxon name is a scientific
or vernacular name attached to that concept. Occurrences retain the exact taxon assigned to the
record; reporting ranks provide a consistent set of levels for summaries.

Lists of `taxon-ranks`, `taxa`, `taxon-names`, `taxon-stats`, and `taxon-year-stats` default to
configured reporting ranks only:

- `reporting_only=true` or `1`: use the default reporting-rank restriction.
- `reporting_only=false` or `0`: return all available exact ranks.

Values are case-insensitive. This option applies only to these list endpoints. Item lookups
return the requested record regardless of reporting status. Occurrences always retain exact
taxon identity and are not restricted by this option.

`parent-taxon` returns the immediate accepted parent via the stored parent relationship.
`parent-taxa` returns projections at the configured reporting ranks; it is not a list of every
ancestor. Neither include changes which taxon the result represents.

See the [glossary](glossary.md) for more about exact and reporting taxa.

## 5. Pagination

All list endpoints use limit/offset pagination:

- `limit`: page size, default `1000`, minimum `1`, maximum `10000`.
- `offset`: zero-based starting position, default `0`.

These pagination limits are fixed in the current implementation, unlike the configurable request
rate limits. Start with a smaller page, such as `limit=100`, while developing.

List responses contain:

- `data`: the resource objects on this page.
- `meta.limit` and `meta.offset`: the applied pagination values.
- `meta.count`: the number of objects on this page.
- `meta.total`: the number of matching objects across all pages, after filters and visibility rules.
- `links.self`, `links.next`, and `links.prev`: relative navigation URLs; unavailable next/previous
  pages use JSON `null`.

### 5.1 Example list response shape

For `GET /api/v1/data-sources?limit=1`, an installation with two matching sources might return:

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
    "limit": 1,
    "offset": 0,
    "count": 1,
    "total": 2
  },
  "links": {
    "self": "/api/v1/data-sources?limit=1&offset=0",
    "next": "/api/v1/data-sources?limit=1&offset=1",
    "prev": null
  }
}
```

Resolve relative links against your installation's origin. Follow `links.next` rather than
reconstructing the URL: links retain the filters, sort order, includes, and page size. Actual
query strings are URL-encoded, so brackets and commas may appear percent-encoded.

Use a unique secondary sort field for repeatable ordering. Offset pagination is not a snapshot:
imports or moderation changes between requests can move records between pages.

## 6. Filtering

List endpoints accept `field=value` or `field[operator]=value`. A bare field means equality.
Filters on different fields and multiple operators on one field are combined with AND; alternatives
inside an `in` filter are combined with OR.

### 6.1 Operators

These are the general operators. Some specialised fields have narrower rules, described below.

| Syntax | Meaning | Example |
| --- | --- | --- |
| `field[eq]=value` | Equals; `field=value` is equivalent | `year[eq]=2025` |
| `field[in]=a,b,c` | Matches any listed value | `taxon_identifier[in]=NHMSYS0021054498,NHMSYS0021700001` |
| `field[contains]=text` | Case-insensitive substring pattern | `scientific_name[contains]=bombus` |
| `field[gte]=value` | Greater than or equal to | `from_date[gte]=2020-01-01` |
| `field[lte]=value` | Less than or equal to | `to_date[lte]=2024-12-31` |

Use `field[eq]=null` to match missing values in nullable scalar fields, for example
`higher_geography_identifier[eq]=null` for a global taxon-statistics row. This is the literal query
value `null`, not an empty string. Special boolean and attribute rules still apply.

`contains` uses a case-insensitive SQL `LIKE` pattern: `%` matches any sequence and `_` matches a
single character. URL-encoding these characters does not turn them into literal matches.

### 6.2 Filterable fields rule

The scalar fields listed for each resource are filterable and sortable unless noted otherwise.
Included scalar fields require their include. Internal fields such as `blocked`, `blocked_reason`,
`created_at`, `updated_at`, and `deleted_at` are not public query fields.

Unknown filter fields, sort fields, operators, and include names return HTTP 400. Do not send
unrelated query parameters, such as cache-busting values, alongside API filters.

### 6.3 Join-table convenience behaviour

Join tables are not separate API resources. For occurrences, filter by a linked region's public
`higher_geography_identifier` without needing `include=geographic-region`:

```text
/api/v1/occurrences?higher_geography_identifier=13&from_date[gte]=2020-01-01&to_date[lte]=2024-12-31
```

The region filter checks membership rather than comparing the returned semicolon-separated
identifier string. Use `eq` or `in` for specific regions. The date example selects records whose
whole date range falls within the requested interval, not every record overlapping it.

### 6.4 Species rarity exclusion

Taxa expose `exclude_from_rarity_calculations` as a JSON boolean and
`exclude_from_rarity_calculations_comment` as a nullable string. The flag defaults to false.
Both fields describe the assigned species' decision, including on subspecies; taxa without an
assigned species return false and null. Only species own editable decisions.

Occurrences, taxon names, taxon statistics, and yearly taxon statistics expose the same effective
values as `taxon__exclude_from_rarity_calculations` and
`taxon__exclude_from_rarity_calculations_comment` with `include=taxon`. Without that include,
these fields and their filters are unavailable.

The exclusion flag supports equality only, with `true`, `false`, `1`, or `0` (case-insensitive).
Other values or operators return HTTP 400. The comment uses normal string filtering.

```text
/api/v1/taxa?exclude_from_rarity_calculations=false
/api/v1/taxon-stats?include=taxon&taxon__rarity_category[lte]=2&taxon__exclude_from_rarity_calculations=false
/api/v1/occurrences?include=taxon&taxon__exclude_from_rarity_calculations=0
```

Requests do not exclude flagged taxa automatically. Clients building rare-species lists must opt
into the filter. Exclusions suppress grid-square rarity contributions from all records assigned
to the species and remove the species from taxon rarity rankings. Recalculation sets its measured
`rarity_category` to null and ranks remaining species without it. Counts, occupancy and independent
global/regional expert rarity statuses remain unchanged, so a flagged species can still have a
regional expert rare status.

Staff edits require manager/admin access and a non-blank rationale. Removing exclusion clears its
rationale. Either flag transition prioritises background square-statistics and taxon-rarity
recalculations. API responses immediately reflect the decision, but square scores and measured
categories change after their respective tasks succeed. Removing exclusion restores category
eligibility on recalculation.
Rationale-only edits do not request recalculation. These are current decisions, not a history.

### 6.5 Configurable taxon attributes

Attributes are deployment-specific and disabled by default. They provide extra taxon properties
without changing the standard resource fields. The `pantheon_*` keys below are examples, not
guaranteed fields.

#### Discover available attributes and terms

Start with `GET /api/v1/taxon-attributes`, or look up a known definition with
`GET /api/v1/taxon-attributes/{key}`. Definitions expose `key`, `label`, `type` (`integer` or
`term`), `multi_value`, Indicia `source_id`, and nullable `termlist_id`.

For controlled terms, use:

```text
/api/v1/taxon-attribute-terms?attribute_key=pantheon_habitat
```

This paginated list returns `attribute_key`, `key`, and `label` for retained, currently used terms.
It excludes terms used only by blocked/deleted taxa and is not the complete warehouse vocabulary.
Term identity is scoped to an attribute; labels are not identities. Discovery sorting uses stable
attribute/term keys to break equal-label ties, keeping pagination repeatable.

The [attribute definition](#1312-taxon-attributes) and
[term](#1313-taxon-attribute-terms) references list the available fields and queries.

#### Include attribute values

On taxa, use `include=taxon-attributes`. On taxon names, occurrences, taxon stats, and yearly taxon
stats, use `include=taxon,taxon-attributes`. Responses add `taxon_attributes`, for example:

```json
{
  "taxon_attributes": {
    "pantheon_sqs": [1],
    "pantheon_broad_biotope": [
      {"key": "indicia:101", "label": "open habitats"},
      {"key": "indicia:102", "label": "tree-associated"}
    ],
    "pantheon_habitat": [],
    "pantheon_sat": []
  }
}
```

This is a response excerpt. The object contains every enabled key, and values are always arrays:

- Integer attributes contain JSON integers.
- Controlled-term attributes contain `key`/`label` objects.
- Single-valued attributes have zero or one item; multi-valued attributes have zero or more
  distinct items.
- Missing values use `[]`; empty configuration returns `{}` for the whole object.
- Requests without the include do not contain `taxon_attributes`.

#### Filter and sort by attributes

Use `attribute__<configured-key>`. Filtering and sorting do not require the `taxon-attributes`
include; related resources still require `include=taxon`.

```text
/api/v1/taxa?attribute__pantheon_sqs[gte]=1&attribute__pantheon_sqs[lte]=5
/api/v1/taxa?attribute__pantheon_habitat[in]=indicia:101,indicia:102
/api/v1/taxa?sort=attribute__pantheon_habitat,-attribute__pantheon_sqs
/api/v1/occurrences?include=taxon&attribute__pantheon_sat=indicia:105
```

Integer attributes support `eq`, `in`, `gte`, and `lte`; controlled terms support `eq` and `in`
using public term keys, not labels. `eq=null` means no assignment and cannot be combined with
other operators. Unknown attributes, unsupported operators, malformed values, and conflicting
bounds return HTTP 400. An `in` predicate accepts at most 100 alternatives. Alternatives match OR;
attributes match AND. Numeric bounds must match the same assignment.

All attributes are sortable. Integer values sort numerically; controlled terms sort by display
label under normal database text collation. Multi-values use the minimum value for ascending
sorts and the maximum for descending sorts. Single-valued attributes use the retained value, not
discarded warehouse alternatives. Missing values sort last in both directions. Attribute sorts
end with a stable resource-key tie-break. Filtering does not hide nonmatching values from the
returned arrays.

Attributes always describe the result's exact taxon: no parent/child inheritance, synonym
expansion at query time, or aggregation across contributing occurrence taxa. Import already unions
entries sharing one taxon meaning. Reporting-only and moderation rules still apply.

Attribute sorting can be expensive on large occurrence lists: a small page limit does not bound
the number of rows the database must aggregate and sort. See the
[testing guide](testing.md#configurable-taxon-attributes) for diagnostic results and limitations.
Installation owners can enable attributes using the
[configuration reference](configuration-reference.md#taxon-attributes).

## 7. Sorting

Use `sort=field` for ascending order or `sort=-field` for descending order. Combine fields with
commas:

```text
/api/v1/occurrences?sort=-from_date,unique_key
```

Sortable fields follow the scalar field and include rules in the resource reference. Each resource
has a default sort listed there. If values can tie, add a unique secondary field for predictable
pagination; the API does not generally append one automatically. Attribute sorts and attribute
discovery have the explicit tie-break rules described above.

## 8. Blocking Rules

- Blocked and soft-deleted occurrences and taxa are excluded from API results and list totals.
- Occurrences, taxon names, taxon stats, and yearly taxon stats exclude results whose owning taxon
  is blocked or deleted.
- An item lookup for an unavailable or blocked resource returns HTTP 404.
- Authentication and `reporting_only=false` do not bypass these visibility rules.

## 9. Response Conventions

### 9.1 Single resource response

An item endpoint returns the resource object directly, without `data`, `meta`, or `links`.
For example, `GET /api/v1/data-sources/NBN` might return:

```json
{
  "abbr": "NBN",
  "title": "NBN Atlas",
  "url": "https://nbnatlas.org"
}
```

### 9.2 List response envelope

All lists, including attribute discovery, use the [pagination envelope](#5-pagination).
An empty list is HTTP 200 with `data: []` and `meta.count: 0`, not HTTP 404. A filter with no
matches has `meta.total: 0`.

### 9.3 Dates, missing values, and types

Date fields use `YYYY-MM-DD`; datetime fields use ISO 8601 in UTC. Occurrence `from_date` and
`to_date` describe the record's date interval, which can span more than one day.

Missing nullable scalar values use JSON `null`, not the string `"null"`. Attribute values use
empty arrays for missing assignments. Media and occurrence-region expansions use empty arrays
when no related items exist.

Some database-derived numbers and flags can be serialised as numbers or numeric strings depending
on the database driver. Explicitly normalised fields include the rarity-exclusion JSON booleans,
attribute-definition `multi_value` boolean, attribute integer values, and media numeric/boolean
metadata. Do not assume every flag in older resources is a JSON boolean; examples include
`accepted=1`, `scientific=0`, and `partial=0`.

## 10. Error Handling

API validation and lookup errors use `application/problem+json` based on RFC 9457
(the successor to RFC 7807). Inspect the HTTP status and `detail` to understand the failure.

### 10.1 Example

```json
{
  "type": "https://api.tanhub.example/problems/invalid-filter-parameter",
  "title": "Invalid filter parameter",
  "status": 400,
  "detail": "Filter field 'foo' is not supported for this resource.",
  "instance": "/api/v1/taxa?foo=bar"
}
```

The `type` URI identifies the problem category; it is not an API base URL to call.

### 10.2 Common status codes

These are the expected outcomes for successful requests and common client errors:

| Status | Meaning | Next step |
| --- | --- | --- |
| `200` | Resource, list, or token response | Read the JSON body; follow pagination links for lists |
| `204` | Token revocation completed | No body to parse |
| `400` | Invalid query or missing required token-request fields | Check `detail`, field names, operators, and include dependencies |
| `401` | Token issuance or refresh failed | Check credentials or obtain a new token pair |
| `404` | Item not found or not publicly available | Check the public identifier and resource path |
| `429` | Rate limit reached | Wait for `Retry-After` before retrying |

## 11. Endpoint Summary

Every resource in the [directory](#41-resources-and-unique-identifiers) has
`GET /api/v1/{resource}`. Every resource except `taxon-attribute-terms` also has
`GET /api/v1/{resource}/{identifier}`, using the directory's item identifier.

Attribute discovery therefore includes all three routes:

- `GET /api/v1/taxon-attributes`
- `GET /api/v1/taxon-attributes/{key}`
- `GET /api/v1/taxon-attribute-terms`

Authentication adds:

- `POST /api/v1/auth/token`
- `POST /api/v1/auth/token/refresh`
- `POST /api/v1/auth/token/revoke`

Media URLs returned by `taxon-media` point to file endpoints outside `/api/v1`:
`/taxon-media/{uuid}` and `/taxon-media/{uuid}/{variant_key}`. Follow the returned URLs rather
than constructing them.

## 12. Non-Goals for v1

- No write operations (`POST`, `PUT`, `PATCH`, or `DELETE`) on core data resources. Token
  management is the exception, not a data-editing API.
- No direct public endpoints for join tables.
- No automatic expansion of every relationship or configurable attribute.

## 13. Resource Reference

Each entry lists the base response fields, supported includes, default sort, and practical queries.
All lists support `limit`, `offset`, and `sort`. Base scalar fields and included scalar fields are
filterable and sortable under the [filtering rules](#6-filtering).

Requests below are relative to your installation. Response excerpts omit unrelated fields and
the repeated list envelope; they are not complete response schemas.

### 13.1 data-sources

Describes the dataset or system supplying records.

- List: `GET /api/v1/data-sources`
- Item: `GET /api/v1/data-sources/{abbr}`
- Fields: `abbr`, `title`, `url`.
- Includes: none.
- Default sort: `abbr`.

```text
/api/v1/data-sources?abbr=NBN
/api/v1/data-sources?title[contains]=atlas&sort=title,abbr
```

### 13.2 geographic-regions

Describes named reporting regions and their types.

- List: `GET /api/v1/geographic-regions`
- Item: `GET /api/v1/geographic-regions/{higher_geography_identifier}`
- Fields: `higher_geography_identifier`, `higher_geography`, `location_type`.
- Includes: `data-source`.
- Default sort: `higher_geography`.

```text
/api/v1/geographic-regions?include=data-source&higher_geography_identifier[in]=12,13,14
/api/v1/geographic-regions?higher_geography[contains]=hampshire&sort=higher_geography,higher_geography_identifier
```

With `include=data-source`, a resource excerpt looks like:

```json
{
  "higher_geography_identifier": 13,
  "higher_geography": "North Hampshire",
  "location_type": "Vice County",
  "data_source__abbr": "IREC",
  "data_source__title": "iRecord",
  "data_source__url": "https://irecord.org.uk"
}
```

### 13.3 occurrences

Represents individual biological records, including the exact recorded taxon and date interval.

- List: `GET /api/v1/occurrences`
- Item: `GET /api/v1/occurrences/{unique_key}`
- Fields: `unique_key`, `taxon_identifier`, `from_date`, `to_date`, `grid_ref`, `grid_ref_2km`,
  `locality`, `recorded_by`, `identified_by`, `identification_verification_status`, `sex`,
  `life_stage`, `organism_quantity`, `higher_geography_identifier`.
- Direct includes: `data-source`, `geographic-region`, `grid-square-stats`, `taxon`, `taxon-name`.
- Includes requiring `taxon`: `taxon-media`, `taxon-rank`, `taxon-group`, `parent-taxon`,
  `parent-taxa`, `taxon-attributes`.
- Default sort: `from_date`.

`higher_geography_identifier` is a semicolon-separated string of linked region identifiers, or
null if none exist. Use `include=geographic-region` for structured region objects. Its filter
checks region membership even without that include.

Use `include=taxon-name` for the supplied name and `include=taxon` for the accepted taxon concept.
Use `include=grid-square-stats` for square coordinates when mapping records.

```text
/api/v1/occurrences?include=taxon,taxon-name,taxon-rank,taxon-group,grid-square-stats,geographic-region&taxon_identifier=NHMSYS0021054498
/api/v1/occurrences?higher_geography_identifier=13&from_date[gte]=2020-01-01&to_date[lte]=2024-12-31
/api/v1/occurrences?recorded_by[contains]=smith&sort=-from_date,unique_key
/api/v1/occurrences?include=taxon,taxon-attributes&attribute__pantheon_sqs[gte]=1
```

Example excerpt with `include=taxon,geographic-region`:

```json
{
  "unique_key": "NBN:123456789",
  "taxon_identifier": "NHMSYS0021054498",
  "from_date": "2024-05-11",
  "to_date": "2024-05-11",
  "higher_geography_identifier": "13",
  "geographic_regions": [
    {
      "higher_geography_identifier": 13,
      "higher_geography": "North Hampshire",
      "location_type": "Vice County"
    }
  ],
  "taxon__scientific_name": "Bombus terrestris",
  "taxon__rarity_category": 5,
  "taxon__exclude_from_rarity_calculations": false,
  "taxon__exclude_from_rarity_calculations_comment": null
}
```

### 13.4 recording-schemes

Describes recording schemes associated with taxa.

- List: `GET /api/v1/recording-schemes`
- Item: `GET /api/v1/recording-schemes/{external_key}`
- Fields: `external_key`, `title`.
- Includes: none.
- Default sort: `title`.

```text
/api/v1/recording-schemes?external_key=ABCD1234EFGH5678
/api/v1/recording-schemes?title[contains]=moth&sort=title,external_key
```

### 13.5 taxa

Represents accepted taxon concepts. Use taxon names to search alternative scientific or vernacular
names associated with those concepts.

- List: `GET /api/v1/taxa`
- Item: `GET /api/v1/taxa/{taxon_identifier}`
- Fields: `taxon_identifier`, `scientific_name_identifier`, `scientific_name`,
  `scientific_name_authorship`, `vernacular_name`, `id_difficulty`, `gb_red_list`, `gb_status`,
  `taxon_remarks`, `rarity_group_name`, `rarity_category`,
  `exclude_from_rarity_calculations`, `exclude_from_rarity_calculations_comment`.
- Includes: `taxon-media`, `recording-scheme`, `taxon-group`, `taxon-rank`, `parent-taxon`,
  `parent-taxa`, **`taxon-attributes`**.
- Default sort: `scientific_name`.
- List option: `reporting_only` (default true).

`rarity_category` is a nullable numeric category from 1 (rare) to 5 (common), not a label such as
`"Common"`. It differs from expert `rarity_status` on taxon statistics.

Relationship keys such as `taxon_group_external_key` and `recording_scheme_external_key` are not
base fields. Request the corresponding include and use `taxon_group__external_key` or
`recording_scheme__external_key`.

```text
/api/v1/taxa?scientific_name[contains]=bombus&sort=scientific_name,taxon_identifier
/api/v1/taxa?gb_red_list[in]=VU,EN,CR&sort=scientific_name,taxon_identifier
/api/v1/taxa?include=taxon-group&taxon_group__external_key=beetles
/api/v1/taxa?include=taxon-attributes&attribute__pantheon_sqs[gte]=1
/api/v1/taxa?reporting_only=false&include=taxon-rank,parent-taxon
```

#### GB designation lists

`gb_red_list` and `gb_status` are nullable strings containing distinct, bytewise-sorted designation
abbreviations separated by `;`, without spaces. Both include old and provisional types within the
warehouse categories `GB Red List` and `GB Status`. No available abbreviations means null.
These are national designations, not the application's calculated or curated local rarity status.

Both fields return effective values: a non-blank `gb_red_list_override` or `gb_status_override`
replaces its corresponding imported value. NULL, empty and ASCII whitespace-only overrides fall
back to the imported value unchanged. Each field resolves independently; clearing an override
restores the latest imported designation rather than suppressing it. Overrides belong to the exact
taxon and do not inherit from parents.

The same effective values drive reads, all supported filters, sorting and pagination on taxa and
the `taxon__` expansions of occurrences, taxon names, taxon stats and taxon-year stats. Raw override
fields are not exposed as public fields, filters or sorts. Editing is available to managers/admins
on the staff taxon details page, not through this read-only API.

The former `conservation_status` and `taxon__conservation_status` fields are no longer returned or
accepted as filters/sorts. Use `gb_red_list` and `taxon__gb_red_list` instead.
Related resources also expose `taxon__gb_status` with `include=taxon`.

Equality and `in` compare complete effective strings:

- `gb_red_list[eq]=LC` does not match `LC;VU`.
- `gb_red_list[eq]=LC%3BVU` matches the whole `LC;VU` list.
- `gb_red_list[in]=LC,VU` matches either single-value string, not a list containing both.
- `contains` is substring matching, not exact membership, and can match part of an abbreviation.

Sorting is lexical over the whole list. There is no individual-designation membership operator.
The same rules apply to included `taxon__` fields.
For example, imported `LC` overridden by `EN;VU` returns `EN;VU`, matches
`gb_red_list[eq]=EN%3BVU`, and no longer matches `gb_red_list[eq]=LC`.

#### Taxon media

`include=taxon-media` returns uploaded media metadata and variant URLs in `taxon_media`.
Items are ordered primary first, then by `sort_order` and internal ID. No media produces `[]`.

Each media object contains:

- `uuid`, `original_filename`, `mime_type`, `bytes`, `width`, `height`.
- Nullable descriptive fields: `alt_text`, `caption`, `attribution`, `license`.
- `sort_order`, `is_primary` (JSON boolean), and the original file `url`.
- `variants`: a map keyed by variant name, or `[]` if no variants exist. Each available variant
  contains `variant_key`, `mime_type`, `bytes`, `width`, `height`, and `url`.

Image dimensions can be null. Available variants depend on the installation and generation
results; do not assume a particular variant always exists. Example excerpt:

```json
{
  "taxon_identifier": "NHMSYS0021054498",
  "taxon_media": [
    {
      "uuid": "99999999-9999-4999-8999-999999999999",
      "original_filename": "specimen.jpg",
      "mime_type": "image/jpeg",
      "bytes": 12345,
      "width": 1200,
      "height": 900,
      "alt_text": "Bombus terrestris specimen",
      "caption": "Specimen image",
      "attribution": "Recorder",
      "license": "CC BY 4.0",
      "sort_order": 0,
      "is_primary": true,
      "url": "https://tanhub.example/taxon-media/99999999-9999-4999-8999-999999999999",
      "variants": {
        "thumbnail": {
          "variant_key": "thumbnail",
          "mime_type": "image/jpeg",
          "bytes": 2345,
          "width": 320,
          "height": 320,
          "url": "https://tanhub.example/taxon-media/99999999-9999-4999-8999-999999999999/thumbnail"
        }
      }
    }
  ]
}
```

### 13.6 taxon-groups

Describes broad groupings such as beetles or dragonflies and damselflies.

- List: `GET /api/v1/taxon-groups`
- Item: `GET /api/v1/taxon-groups/{external_key}`
- Fields: `external_key`, `title`, `friendly`, `indicia_taxon_group_id`, `implied`.
- Includes: none.
- Default sort: `title`.

`friendly` is a display name; use `external_key` for the stable group identity.

```text
/api/v1/taxon-groups?friendly[contains]=dragon
/api/v1/taxon-groups?indicia_taxon_group_id=7
```

### 13.7 taxon-names

Searches scientific and vernacular names, including alternative names, and links them to accepted
taxon concepts.

- List: `GET /api/v1/taxon-names`
- Item: `GET /api/v1/taxon-names/{uuid}`
- Fields: `uuid`, `taxon_identifier`, `given_name_identifier`, `name`, `accepted`, `scientific`.
- Direct includes: `taxon`, `taxon-media`, `taxon-rank`, `taxon-group`, `parent-taxon`,
  `parent-taxa`.
- Include requiring `taxon`: `taxon-attributes`.
- Default sort: `name`.
- List option: `reporting_only` (default true).

`accepted` identifies accepted names; `scientific` distinguishes scientific names from vernacular
names. `given_name_identifier` identifies the name, whereas `taxon_identifier` identifies the
taxon concept. The base name resource does not expose `scientific_name_identifier`.

```text
/api/v1/taxon-names?name[contains]=robin&sort=name,uuid
/api/v1/taxon-names?accepted=1&scientific=0
/api/v1/taxon-names?taxon_identifier=NHMSYS0021054498
/api/v1/taxon-names?include=taxon,taxon-attributes&name[contains]=bombus
```

### 13.8 taxon-ranks

Lists available taxonomic ranks and identifies which are used for reporting.

- List: `GET /api/v1/taxon-ranks`
- Item: `GET /api/v1/taxon-ranks/{abbr}`
- Fields: `rank`, `abbr`, `sort_order`, `is_reporting`.
- Includes: none.
- Default sort: `sort_order`.
- List option: `reporting_only` (default true).

```text
/api/v1/taxon-ranks?reporting_only=false&sort=sort_order,abbr
/api/v1/taxon-ranks/sp
```

### 13.9 grid-square-stats

Provides counts, mapping coordinates, and a derived rarity score for squares.

- List: `GET /api/v1/grid-square-stats`
- Item: `GET /api/v1/grid-square-stats/{uuid}`
- Fields: `uuid`, `square`, `easting`, `northing`, `lon`, `lat`, `partial`, `occurrences_count`,
  `species_count`, `rarity_score`, `higher_geography_identifier`.
- Includes: `geographic-region` (with a hyphen, not `geographic_region`).
- Default sort: `square`.

`partial` identifies partial squares. `rarity_score` is a nullable decimal derived field: it remains
null until the square-statistics task has run for the imported squares.

```text
/api/v1/grid-square-stats?include=geographic-region&partial=0
/api/v1/grid-square-stats?higher_geography_identifier=13&species_count[gte]=50&sort=-species_count,uuid
```

### 13.10 taxon-stats

Provides one taxon's statistics and expert decisions for a global or regional scope. A taxon can
have multiple rows: use the region filter when you need one scope.

- List: `GET /api/v1/taxon-stats`
- Item: `GET /api/v1/taxon-stats/{uuid}`
- Fields:
  - Identity and scope: `uuid`, `taxon_identifier`, `higher_geography_identifier`.
  - Counts and occupancy: `occurrences_count`, `grid_square_count`, `mean_squares_per_year`,
    `mean_occurrences_per_year`, `occupied_square_score`, `occupied_square_percentage`.
  - Expert rarity decision: `rarity_status`, `rarity_status_comment`, `rarity_status_decision_date`.
  - Trends: `frequency_trend`, `frequency_trend_state`.
  - Key-species decision: `key_species`, `key_species_comment`, `key_species_decision_date`.
  - Record history: `first_record_date`, `last_record_date`, `first_recorder`, `last_recorder`,
    `first_verified_record_date`, `last_verified_record_date`, `first_verified_recorder`,
    `last_verified_recorder`.
- Extra filter: `taxon_rank` (for example `taxon_rank=species`); this is not a returned field or
  a sort key.
- Direct includes: `geographic-region`, `taxon`.
- Includes requiring `taxon`: `taxon-media`, `taxon-rank`, `taxon-group`, `parent-taxon`,
  `parent-taxa`, `taxon-attributes`.
- Default sort: `uuid`.
- List option: `reporting_only` (default true).

#### Scope and yearly means

Use `higher_geography_identifier[eq]=null` to select global rows, or a specific identifier for
regional rows. Rows for deleted regions are excluded, so the null filter selects only global rows.
Global and regional expert decisions are independent.

`mean_squares_per_year` and `mean_occurrences_per_year` are means over completed calendar years
in the configured yearly-statistics history window. Years without a row count as zero; the current
year is excluded because its data is incomplete.

#### Occupancy and expert rarity

`occupied_square_score` and `occupied_square_percentage` are nullable species-only measurements,
rounded to four decimals.

- The score is a SQL-style percent-rank of positive occupied-square counts within the same rarity
  group and global/regional scope; lower means rarer.
- Ties share scores, singletons receive 50, all-tied larger groups receive 0, and tied maxima may
  be below 100.
- Percentages use all mapped squares, including partial and unrecorded squares; globally,
  overlapping regions are deduplicated. Percentages do not require a rarity group.
- Scopes that exist only to hold a decision have null measurements.

`rarity_status` is `common`, `rare`, or JSON `null` for no decision. Its nullable comment and ISO
decision date describe the current expert decision, not an audit history. An expert override does
not change occupancy measurements or calculated rarity categories.

For example, `occupied_square_score[lte]=5` selects the bottom score band, whereas
`occupied_square_percentage[lte]=0.6` selects coverage at or below 0.6%. Filter expert decisions
with `rarity_status=rare` or `rarity_status[eq]=null`.

See the [full calculation contract](database.md#taxon_stats) for the measurement rules.

#### Trends

`frequency_trend` is a nullable relative score from 0 (most declining) to 100 (most increasing),
not a percentage change in population. `frequency_trend_state` is `increasing`, `decreasing`,
`stable`, `insufficient_data`, `unclear`, or null. Filter by `taxon_rank` when reviewing trends
rather than comparing scores across different taxonomic levels.

See [derived frequency trends](import.md#derived-taxon-frequency-trends) for the calculation.

#### Key-species decisions

`key_species` returns `yes`, `no`, or `not set`. Its comment and decision date are nullable and
describe the current decision. Changing a decision updates the date; **Not set** clears both
comment and date.

Equality filters accept raw `1`, `0`, or `null` values, or mapped `yes`, `no`, or `not set` values
(case-insensitive). For example:

```text
/api/v1/taxon-stats?key_species=yes&higher_geography_identifier=13&include=taxon
/api/v1/taxon-stats?key_species[eq]=not%20set&higher_geography_identifier[eq]=null
/api/v1/taxon-stats?taxon_rank=species&frequency_trend_state=decreasing&sort=frequency_trend,uuid
/api/v1/taxon-stats?include=taxon,taxon-attributes&taxon_identifier=NHMSYS0021054498
/api/v1/taxon-stats?higher_geography_identifier=13&occurrences_count[gte]=10&sort=-occurrences_count,uuid
```

### 13.11 taxon-year-stats

Provides a taxon's counts for a year and a global or regional scope.

- List: `GET /api/v1/taxon-year-stats`
- Item: `GET /api/v1/taxon-year-stats/{uuid}`
- Fields: `uuid`, `taxon_identifier`, `higher_geography_identifier`, `year`, `occurrences_count`,
  `grid_square_count`.
- Direct includes: `geographic-region`, `taxon`.
- Includes requiring `taxon`: `taxon-media`, `taxon-rank`, `taxon-group`, `parent-taxon`,
  `parent-taxa`, `taxon-attributes`.
- Default sort: `year`.
- List option: `reporting_only` (default true).

Use an explicit scope filter when comparing years. A null region identifier denotes global scope;
requesting `include=geographic-region` on a global row does not supply a regional name.

```text
/api/v1/taxon-year-stats?taxon_identifier=NHMSYS0021054498&higher_geography_identifier[eq]=null&year[gte]=2016&sort=year,uuid
/api/v1/taxon-year-stats?higher_geography_identifier=13&year=2025&sort=-occurrences_count,uuid
/api/v1/taxon-year-stats?include=taxon,taxon-attributes&year=2025
```

### 13.12 taxon-attributes

Discovers enabled attribute definitions. Only enabled definitions stored on this installation are
returned; with no configured attributes the list is empty.

- List: `GET /api/v1/taxon-attributes`
- Item: `GET /api/v1/taxon-attributes/{key}`
- Fields: `key`, `label`, `type`, `multi_value`, `source_id`, `termlist_id`.
- Includes: none.
- Default sort: `label`, with `key` as a stable tie-break.

`type` is `integer` or `term`; `multi_value` is a JSON boolean. `source_id` is the Indicia
attribute ID, not a local primary key. `termlist_id` is nullable.

```text
/api/v1/taxon-attributes?type=term&sort=label
/api/v1/taxon-attributes/pantheon_sqs
```

Use the public `key` when requesting values or building `attribute__<key>` queries. See
[configurable taxon attributes](#65-configurable-taxon-attributes) for the full value contract.

### 13.13 taxon-attribute-terms

Discovers controlled terms currently retained on publicly visible taxa, not the complete source
vocabulary. Terms used only by blocked/deleted taxa are excluded.

- List: `GET /api/v1/taxon-attribute-terms`
- Item: none; term keys are scoped to their attribute.
- Fields: `attribute_key`, `key`, `label`.
- Includes: none.
- Default sort: `label`, with `attribute_key` and `key` as stable tie-breaks.

```text
/api/v1/taxon-attribute-terms?attribute_key=pantheon_habitat&sort=label&limit=100
/api/v1/taxon-attribute-terms?attribute_key=pantheon_habitat&key=indicia:101
```

Without `attribute_key`, the list can contain terms from multiple enabled attributes. Always keep
the attribute key together with the term key; labels are for display, not identity or filtering
taxon assignments.

## 14. Implementation Notes for Developers

When extending the API:

- Expose stable relationship identifiers such as `taxon_identifier`, `external_key`, and `abbr`,
  not internal numeric primary keys.
- Validate allowed filters, sort fields, include values, and dependency rules per resource.
- Return RFC 9457 problem responses for API validation and lookup failures.
- Keep case-insensitive behaviour for `contains` filters.
- Apply blocked/deleted-record exclusion before counting pagination totals.
- Keep item and list include behaviour consistent.
- Document fields, types, defaults, and examples here, and update the OpenAPI specification in
  the same change.

## 15. OpenAPI Specification

The [OpenAPI 3.1 specification](openapi.v1.yaml) describes routes, optional bearer authentication,
common query parameters, response envelopes, and selected shared schemas.

Resource schemas are still partly generic: use this guide's field lists and specialised contracts
for details that the machine-readable schema does not yet enumerate. Do not treat a generic
`ResourceItem` schema as permission to filter arbitrary fields.

## See also

- [OpenAPI v1 specification](openapi.v1.yaml)
- [Database schema](database.md)
- [Glossary](glossary.md)
- [Configuration reference](configuration-reference.md)
- [Troubleshooting](troubleshooting.md)
