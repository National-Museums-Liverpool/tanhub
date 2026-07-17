# Admin user interface

## Purpose

The admin user interface allows authenticated staff to manage TanHub reference
data and moderate imported biological records safely.

This document defines the intended UI behaviour and access rules for the first
release.

## Roles and access

The admin UI supports two staff roles:

- Admin: full access, including user management.
- Manager: operational data management access, excluding user management.

General rules:

- All admin UI pages require login.
- Pages that can change data must be protected by CSRF and server-side
  validation.
- Destructive actions require explicit confirmation.

## Navigation

Each top-level data section has a list page linked from the main menu.

Top-level sections are:

- Users
- Taxon groups
- Taxon ranks
- Geographic regions
- Recording schemes
- Taxa
- Taxon names
- Occurrences
- Data sources
- Imports

## Shared page behaviour

All list pages should follow the same interaction model:

- Table columns with clickable sort headers.
- Default sort order defined per table and documented below.
- Pagination with current filters and sort preserved.
- Optional search and filters where useful.
- Empty-state message when no records match.

All detail pages should follow the same interaction model:

- Display immutable fields as disabled inputs with a "Read-only" badge.
- Show validation messages inline and at top-level summary.
- Show a success flash message after save.
- Provide a clear "Back to list" action.

## Imports

Provides a page showing a table of the following import tasks and sources (corresponding to the
import Spark commands listed in [the Import documentation](import.md)), grouped into categories for
lookups, taxonomy, occurrences and report stats:
- Lookups
  - recording_schemes (Indicia)
  - geographic_regions (Indicia)
- taxonomy
  - taxon_groups (Indicia)
  - taxon_ranks (Indicia)
  - taxa (Indicia)
  - taxon_names (Indicia)
- occurrences
  - occurrences (Indicia)
  - occurrences (NBN)
- report stats:
  - grid_square_stats
  - taxon_stats
  - taxon_year_stats

Each task in the table shows the task name, data source (not relevant for report stats), next
offset or checkpoint, is complete and has a "Go" button allowing the task to be run from the UI. If
the import task is blocked because it depends on another task which is not yet complete it shows
"Blocked by ..." with a list of the blocking tasks instead of a Go button.

When a task is run by clicking Go, it is added to a queue of tasks to process. The first task
starts processing immediately and its Go button is replaced with a "running" badge. When it
finishes, the Go button is restored and any other tasks blocked by this task are unblocked, only
if the task is complete. Any other tasks in the queue then proceed in the order they were added.

The current queue is shown on the page.

## Table coverage and behaviour

### Users

Access:

- List/view/edit/create/deactivate/delete: Admin only.

List page:

- Columns: id, username, email, groups, active status, created_at, actions.
- Filters: group, active status.
- Actions: create user, edit user, deactivate/reactivate, soft delete.

Edit page:

- Editable: username, email, active status, groups.
- Password reset as a separate explicit action.

### Taxon groups

Access:

- List/view: Admin and Manager.
- Edit: Admin and Manager.

List page:

- Columns: id, title, friendly, external_key, implied, actions.
- Generic search: `q` across title, friendly, external_key, and indicia_taxon_group_id.
- Default sort: title asc.

Edit page:

- Read-only: id, title, external_key, implied.
- Editable: friendly.

### Taxon ranks

Access:

- List/view: Admin and Manager.

List page:

- Columns: id, rank, abbr, sort_order, actions.
- Generic search: `q` across rank, abbr, and sort_order.
- Default sort: sort_order asc.

Detail page (read-only):

- Shows id, rank, abbr, and sort_order.

### Geographic regions

Access:

- List/view: Admin and Manager.

List page:

- Columns: id, geographic_region_identifier, region,
  location_type, occurrences, links.
- Generic search: `q` across geographic_region_identifier, region, and location_type.
- Default sort: geographic_region_identifier asc.

Detail page (read-only):

- Shows all geographic_region fields.
- Shows count of related occurrence records.

### Recording schemes

Access:

- List/view: Admin and Manager.

List page:

- Columns: id, external_key, title, taxa_count, view.
- Generic search: `q` across external_key and title.
- Default sort: title asc.

Detail page (read-only):

- Shows all scheme fields.
- Shows count of related taxa records.

### Taxa

Access:

- List/view: Admin and Manager.
- Edit moderation fields: Admin only.

List page:

- Columns: id, taxon_identifier, scientific_name, vernacular_name,
  conservation_status, blocked, actions.
- Generic search: `q` across taxon_identifier, scientific_name,
  vernacular_name, and conservation_status.
- Filters: taxon_group, taxon_rank, recording_scheme, blocked.
- Default sort: scientific_name asc.

Detail/edit page:

- Read-only: taxon_identifier, scientific_name_identifier, scientific_name,
  vernacular_name, classification FKs.
- Classification FKs are dynamic self-references on taxa (for example
  order_id, family_id, species_id) rather than separate order, family, or
  superfamily tables.
- Read-only table: associated taxon names (name, given_name_identifier,
  accepted, scientific).
- Editable: blocked, blocked_reason (admin only).

### Taxon names

- Taxon names are shown as a read-only table on the taxa detail page.

### Occurrences

Access:

- List/view: Admin and Manager.
- Edit moderation fields: Admin and Manager.

List page:

- Columns: id, unique_key, taxon_id, taxon_name_id, from_date, to_date,
  grid_ref, data_source_id, blocked, actions.
- Filters: data_source, date range, blocked, taxon_id.
- Default sort: from_date desc, id desc.

Detail/edit page:

- Read-only: source identity and biological identity fields.
- Editable: blocked, blocked_reason.

### Data sources

Access:

- List/view: Admin and Manager.
- Create/edit: Admin only.

List page:

- Columns: id, abbr, title, url, actions.
- Default sort: title asc.

Edit page:

- Editable: abbr, title, url.
- abbr and title must validate as unique.

## Validation and safety rules

- All uniqueness constraints in schema must be validated in forms and handled
  gracefully on save.
- All FK selections must be validated against existing rows.
- blocked_reason is required when blocked is true.
- Audit metadata (created_at, updated_at, deleted_at) is never manually edited.

## URL structure

Use consistent plural nouns and id-based edit/view paths:

- /users
- /users/{id}/edit
- /taxon-groups
- /taxon-groups/{id}/edit
- /taxon-ranks
- /taxon-ranks/{id}
- /recording-schemes
- /recording-schemes/{id}
- /taxa
- /taxa/{id}
- /taxa/{id}/edit
- /taxon-names
- /taxon-names/{id}
- /occurrences
- /occurrences/{id}
- /occurrences/{id}/edit
- /data-sources
- /data-sources/{id}/edit

## Success criteria for the first release

- Staff can find every key table from the main menu.
- Sorting and pagination work consistently across all list pages.
- Read-only vs editable fields are clear on every edit screen.
- Managers can perform operational moderation tasks without Admin rights.
- Admins can manage users and lookup sources safely.
