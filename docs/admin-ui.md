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
- Orders
- Superfamilies
- Families
- Recording schemes
- Taxa
- Taxon names
- Occurrences
- Data sources

## Shared page behaviour

All list pages should follow the same interaction model:

- Table columns with clickable sort headers.
- Default sort order defined per table and documented below.
- Pagination with current filters and sort preserved.
- Optional search and filters where useful.
- Empty-state message when no records match.

All detail/edit pages should follow the same interaction model:

- Display immutable fields as disabled inputs with a "Read-only" badge.
- Show validation messages inline and at top-level summary.
- Show a success flash message after save.
- Provide a clear "Back to list" action.

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

- Columns: id, title, friendly, external_key, actions.
- Default sort: title asc.

Edit page:

- Read-only: id, title, external_key.
- Editable: friendly.

### Orders

Access:

- List/view: Admin and Manager.

List page:

- Columns: id, taxon_identifier, scientific_name, vernacular_name, taxa_count,
  actions.
- Default sort: scientific_name asc.

Detail page (read-only):

- Shows all order fields.
- Shows count of related taxa records.

### Superfamilies

Access:

- List/view: Admin and Manager.

List page:

- Columns: id, taxon_identifier, scientific_name, vernacular_name, taxa_count,
  actions.
- Default sort: scientific_name asc.

Detail page (read-only):

- Shows all superfamily fields.
- Shows count of related taxa records.

### Families

Access:

- List/view: Admin and Manager.

List page:

- Columns: id, taxon_identifier, scientific_name, vernacular_name, taxa_count,
  actions.
- Default sort: scientific_name asc.

Detail page (read-only):

- Shows all family fields.
- Shows count of related taxa records.

### Recording schemes

Access:

- List/view: Admin and Manager.

List page:

- Columns: id, external_key, title, taxa_count, actions.
- Default sort: title asc.

Detail page (read-only):

- Shows all scheme fields.
- Shows count of related taxa records.

### Taxa

Access:

- List/view: Admin and Manager.
- Edit moderation fields: Admin and Manager.

List page:

- Columns: id, taxon_identifier, scientific_name, vernacular_name,
  conservation_status, blocked, actions.
- Filters: taxon_group, order, superfamily, family, recording_scheme, blocked.
- Default sort: scientific_name asc.

Detail/edit page:

- Read-only: taxon_identifier, scientific_name_identifier, scientific_name,
  vernacular_name, classification FKs.
- Editable: taxon_remarks, conservation_status, blocked, blocked_reason,
  rarity_group_name, id_difficulty.

### Taxon names

Access:

- List/view: Admin and Manager.

List page:

- Columns: id, uuid, taxon_id, name, scientific_name_identifier, accepted,
  scientific.
- Filters: accepted, scientific, taxon_id.
- Default sort: name asc.

Detail page:

- Read-only display only.

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
- /orders
- /orders/{id}
- /superfamilies
- /superfamilies/{id}
- /families
- /families/{id}
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
