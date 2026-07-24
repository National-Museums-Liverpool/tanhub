# Admin user interface

## Purpose

The admin user interface allows authenticated staff to manage tanhub reference
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

The menu hierarchy is:

- Lookups
  - Data sources
  - Geographic regions
  - Recording schemes
- Taxonomy
  - Taxon groups
  - Taxon ranks
  - Taxa
- Occurrences
- Imports
- Users

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

## Users

Access:

- List/create/edit: Admin only.
- Self-registration is disabled after initial setup.

List page:

- Columns: id, username, email, active, groups, created, actions.
- Supports sort and search.

Create page:

- Fields: username, email, active, password, password_confirm.
- Password is required on create.

Edit page:

- Editable: username, email, active, password.
- Password is optional on edit; when supplied it updates the stored password.
- Setting active to false blocks login for that account.

## Imports

Provides a page showing a table of the following import tasks and sources (corresponding to the
import Spark commands listed in [the Import documentation](import.md)), grouped into categories for
lookups, taxonomy, occurrences and report stats:
- Lookups
  - recording_schemes (Indicia)
  - geographic_regions (Indicia)
  - grid_square_stats (Indicia)
- taxonomy
  - taxon_groups (Indicia)
  - taxon_ranks (Indicia)
  - taxa (Indicia)
  - taxon_names (Indicia)
- occurrences
  - occurrences (Indicia)
  - occurrences (NBN, not implemented)
- report stats:
  - grid_square_stats_counts
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

`grid_square_stats_counts` is a derived task that recalculates
`grid_square_stats.occurrences_count` and `grid_square_stats.species_count`
from active occurrences after both occurrence import streams complete.

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

- Columns: id, taxon_identifier, scientific_name, vernacular_name, conservation_status, blocked,
  actions.
- Generic search: `q` across taxon_identifier, scientific_name, vernacular_name, and
  conservation_status.
- Filters: taxon_group, taxon_rank, recording_scheme, blocked.
- Default sort: scientific_name asc.

Detail/edit page:

Allows you to review the details stored for a taxon, including the names. Admins can block a taxon
from appearing in reports and set its blocked reason. Admins and managers can specify remarks for
a taxon and override the rarity group name. The rarity group name defaults to the associated
recording scheme name and groups taxa together into collections within which they can be compared
for rarity calculations, so they are only compared to similarly recorded taxa.

- Read-only: taxon_identifier, scientific_name_identifier, scientific_name, vernacular_name,
  classification FKs.
- Classification FKs are dynamic self-references on taxa (for example order_id, family_id,
  species_id) rather than separate order, family, or superfamily tables.
- Read-only table: associated taxon names (name, given_name_identifier, accepted, scientific).
- Editable: blocked, blocked_reason (admin only), taxon_remarks, rarity_group_name (manager or
  admin only).
- Taxon media upload:
  - Route: POST /taxa/{id}/media.
  - Roles allowed: admin and manager.
  - Allowed uploads: image/jpeg, image/png, image/gif, image/webp.
  - Validation: file is required, metadata fields are optional with server-side length checks.
  - Display: the details page shows existing media with metadata and variant links.
  - Public delivery URLs: /taxon-media/{uuid} and /taxon-media/{uuid}/{variant_key}.

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

For info only, data sources are created as part of the installation and each data source has
associated import code so they cannot be edited via the UI.

Access:

- List/view: Admin and Manager.

List page:

- Columns: id, abbr, title, url, actions.
- Default sort: title asc.

Details page:

- Read only: id, abbr, title, url.

## Validation and safety rules

- All uniqueness constraints in schema must be validated in forms and handled gracefully on save.
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
