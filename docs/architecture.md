# Architecture

Tanhub is a CodeIgniter 4 application designed to be straightforward to host on
a shared server. It stores reporting-oriented wildlife data in MySQL and exposes
both staff administration pages and a read-only REST API.

## Application layers

- `app/Config/Routes.php` maps browser, administration, media, and API URLs to
  controllers.
- `app/Controllers` contains the staff-facing web controllers. They render the
  views in `app/Views` and require a Shield session and the appropriate staff
  group.
- `app/Controllers/Api/V1` contains the public v1 API controllers. Shared API
  response, filtering, sorting, pagination, and include behaviour is provided
  by `ApiResourceController`.
- `app/Models` owns database access for application entities and import-tracking
  tables.
- `app/Services` contains business operations that do not belong in a controller
  or model. This includes import, derived-statistics, and taxon-media services.
- `app/Filters` provides cross-cutting request handling. API requests use CORS
  and rate-limiting filters; staff routes use the Shield session and group
  filters.
- `app/Commands` exposes imports, derived-statistics calculations, and media
  maintenance through CodeIgniter Spark.

## Request flows

### Staff administration

A request such as `GET /taxa` is routed to `Taxa::index`. The session and
`group:admin,manager` filters run before the controller. The controller obtains
filtered, sorted, and paginated data through its model before rendering the
corresponding view.

### Public API

A request such as `GET /api/v1/taxa?include=taxon-media` is routed to an API v1
controller. The CORS and `api-rate-limit` filters run first. The controller uses
the shared API resource behaviour to validate query parameters, retrieve and
expand the requested data, then returns the standard JSON response envelope or
an RFC 9457 problem response.

### Imports and derived statistics

Imports can start from a Spark command or from the staff Imports page. The
import orchestrator selects a source adapter, fetches a page of external data,
passes normalised rows to an entity-specific persistence service, and records
the resulting checkpoint and run information. `import_offsets`, `import_runs`,
and `import_task_queue` support resumable imports and the staff task queue.

Derived-statistics commands aggregate imported occurrences into grid-square,
taxon-rarity, taxon-statistics, and taxon-year-statistics tables. See
[Import](import.md) for the operational sequence and [Database schema](database.md)
for the stored data model.

## External systems

The Indicia warehouse is the primary source of UKSI taxonomy and occurrence
data. Its REST endpoints and supporting reports are configured through
`Config\Import` and the environment.

The import layer also includes an NBN Atlas occurrence adapter. It requires a
compatible endpoint configuration before it can fetch records. Source-specific
filters and import state are kept within the import services rather than in the
public API layer.

## Authentication and authorisation

CodeIgniter Shield manages staff users, passwords, sessions, and API tokens.
Administration routes require either the `admin` or `manager` group according
to the operation. User management is restricted to `admin`. API requests may
be anonymous or authenticated with a Bearer token; authenticated clients use a
separate rate-limit bucket.

## See also

- [Database schema](database.md) describes entities and relationships.
- [API reference](api.md) defines public API behaviour.
- [Import](import.md) describes source setup and operational commands.
- [Configuration reference](configuration-reference.md) lists supported
  environment settings.