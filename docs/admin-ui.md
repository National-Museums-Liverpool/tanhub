# Admin user interface

## CSV downloads

Admins and managers can use **Download CSV**, next to Search on the taxa and occurrences lists.
It exports all matching rows using the applied filters and grid sort, not just the current page.
Apply edited form values with Search first: download confirmation uses the displayed search.

Choose a grid-equivalent template, detailed occurrences, or taxa with stored all-date statistics.
Detailed occurrences include raw dates, identifiers, recorder/identifier, locality, coordinates
and moderation context. Stored statistics use the selected region, or global scope; year/source
filters select qualifying taxa but do not recalculate the stored statistics.
Statistics headings use short names such as **Occurrences count** and **Stats available**.
The shared statistics scope appears in export metadata rather than repeating in every CSV row.

New exports start processing automatically when the progress page opens. Capture shows
committed row counts against the estimated total passed from the grid. This estimate can change
as live data changes; it never limits the export or determines when capture finishes.
CSV writing shows progress against the exact captured total. Links without a grid count show an
activity indicator during capture. **Pause** stops after the current request. **My downloads**
lets you return and click **Resume**. Closing the tab pauses work. Without JavaScript,
**Process next batch** advances one batch
at a time. Confirmation displays search-control captions and selected names rather than internal
IDs. Progress identifies the chosen column template by its full name; its version identifies the
column layout, not the data version.

This is a rolling capture, not a point-in-time snapshot. Capture applies the search filters in SQL,
then selects a batch of matching IDs in ascending order up to the ID ceiling set at creation.
It does not send batches of unrelated records through the browser. Already captured values are
frozen. Edits/deletions before a row is reached can change membership; edits to IDs already passed
are not revisited. Later
higher-ID inserts are excluded. CSV ordering uses the captured values and original grid sort.

Downloads, progress and history are private to the requesting staff member, including for admins.
Completed files expire after 24 hours by default; unfinished exports expire after 24 hours without
committed progress. A maximum of two unfinished exports per user prevents abandoned work
accumulating. Deployment settings can change these limits.

If a request fails, committed batches remain safe. Reload to see server progress and Resume after
resolving the reported error. **Regenerate CSV** is available during writing to rebuild output from
the staged values without recapturing live data. Cancel and create a new export for a fresh capture.

CSV uses UTF-8 with a BOM, quoted fields and ISO raw dates. Text that could execute as a spreadsheet
formula receives a leading apostrophe; typed numeric values keep their sign. Geographic regions
are semicolon-separated. Missing statistics are explicitly unavailable, not zero counts.
Excel cannot display more than 1,048,576 rows in a worksheet: use a suitable data tool for larger
files. ZIP and automatic file splitting are not offered in this release.

## Purpose

The admin user interface gives authenticated staff a place to manage reference data, moderate
imported records, and monitor imports.

This document defines the intended UI behaviour and access rules for the first
release.

## Roles and access

The admin UI supports two staff roles:

- Admin: full access, including user management.
- Manager: operational data management access, excluding user management.

General rules:

- All admin UI pages require login.
- Pages that can change data must be protected by CSRF (a browser request-forgery defence) and
  server-side validation.
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

## Typical day-to-day tasks for managers

Most day-to-day work involves improving how taxa are presented, or checking records before they
appear in reports and visualisations. The steps below use the names in the main menu.

### Add a friendly name to a taxon group

A friendly name is the clear, public-facing name shown instead of a source system's name. For
example, you might change a technical group name to “Bees, Wasps and Ants”.

1. Open **Taxonomy > Taxon groups**.
2. Find the group and open it for editing.
3. Enter the preferred wording in **Friendly**. Leave the other fields unchanged.
4. Save the group and check that the new name is displayed.

### Block a taxon

Blocking a taxon keeps it out of reports. Always give a short reason so another person can
understand the decision later. Taxon blocking is currently an Admin-only task. A manager who needs
to block a taxon should ask an Admin to make the change and provide the reason.

1. Open **Taxonomy > Taxa** and search for the taxon.
2. Open the taxon's details page and choose **Edit**.
3. Set **Blocked** to **Yes** and enter the reason in **Blocked reason**.
4. Save the changes. To allow the taxon in reports again, set **Blocked** to **No** and save.

### Block an occurrence

Blocking an occurrence removes that record from reports without deleting it. Record why it should
not be used, for example because it is a duplicate or has been identified as an error.

1. Open **Occurrences** and search or filter for the record.
2. Open the occurrence and choose **Edit**.
3. Set **Blocked** to **Yes** and enter the reason in **Blocked reason**.
4. Save the changes. To include the occurrence in reports again, set **Blocked** to **No** and
  save.

### Add remarks to a taxon

Use remarks for useful text about a taxon, such as a description or species account. This text is
used to provide species account text in tanvis visualisation outputs.

1. Open **Taxonomy > Taxa** and search for the taxon.
2. Open the taxon's details page and choose **Edit**.
3. Enter or update the text in **Taxon remarks**.
4. Save the changes.

### Set a key-species decision

Managers and admins can record whether a taxon is a key species in each active geographic region,
and set an independent decision for the global scope. Each scope has a decision of **Yes**, **No**,
or **Not set**, plus a current rationale comment. The decision date is set when the decision
changes to Yes or No; editing only the comment does not change the date. Choosing **Not set**
clears the comment and decision date. These fields show the current decision, not a history of
earlier decisions.

1. Open **Taxonomy > Taxa**, find the taxon, and open its details page.
2. In **Key species by scope**, choose a decision for each region or for **Global**.
3. Add or update the rationale comment where useful, then save the page.

### Add a media file to a taxon

You can attach an image to an individual taxon from its details page. Use a clear, relevant image
and add descriptive information where it is available.

1. Open **Taxonomy > Taxa** and search for the taxon.
2. Open the taxon's details page and find the **Taxon media** section.
3. Choose an image file, add any available alternative text, caption, attribution, licence, or
  primary-image setting, then upload it.
4. Check that the new image appears in the taxon's media list.

### Bulk upload taxon media

Use bulk upload when adding images to many taxa at once. Prepare a CSV containing a taxon
identifier or name and a matching filename for each image, then upload the CSV and image files from
**Taxonomy > Bulk media import**. The page shows progress while the files are checked and processed.

For the required columns, accepted identifiers, matching rules, and what to do when processing is
paused, see [Bulk taxon media import](administration.md#bulk-taxon-media-import).

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

## Occurrence and taxa searches

Both indexes support broad descriptive text search, year, geographic region, taxon group,
configured classification filters (Family by default), and a taxon-name selector. Different
filters combine with AND. Text search matches any of the supported descriptive fields.

On occurrences, text search includes taxon names and synonyms, recorded names, identifiers,
classification/group labels, region names, locality, grid reference, recorder, identifier and
data-source labels. On taxa it searches the taxon's names, synonyms, identifiers, classification,
group labels, conservation status, rarity group and remarks, not descendant record text.

### Dates and regional checklists

The Year field accepts `2010`, `2010-2015`, `2010-` or `-2015`. All bounds are inclusive.
A record's complete date interval must fit inside the chosen range. A record dated 2010-2012
therefore does not qualify for 2011, but does qualify for 2010-2012. Records with missing date
bounds do not qualify for date-filtered searches.

Only the text search and Search/Clear controls are shown initially. Expand Advanced for all
other filters, including year, region, classification, taxon selection, precise date bounds,
source and independent taxon/occurrence blocked filters. The panel opens automatically when
an advanced filter is active or invalid, so hidden filters cannot silently affect the results.
Expanded controls sit in a bordered, rounded, lightly shaded panel with internal spacing.
Year and precise date constraints both apply when supplied together. Blocked data
is included by default because these are staff pages; select No for both blocked filters
when producing a checklist that excludes blocked evidence. Deleted data is always excluded.
On taxa, Taxon blocked = No also excludes evidence identified as a blocked child taxon.
Taxon blocked = Yes selects blocked listed taxa without requiring their children to be blocked.

On taxa, choose Rank = Species to list species with matching records, or Rank = Genus to list
genera. Rank is independent of classification: Family = Noctuidae and Rank = Genus lists genera
in that family. The Rank selector offers configured reporting ranks, not every imported rank.
There is no Rank filter on occurrences, whose rows remain original individual records.

Year, region, source and occurrence status must match the same contributing record. A record
of a child taxon qualifies its configured reporting ancestors, so subspecies evidence can qualify
a species, and a blackbird record can qualify Aves if Class and that ancestor have been
configured and imported. This does not create missing ancestors or change import scope.
When Rank is blank, non-reporting taxa retain exact-record evidence; unconfigured ancestor
roll-up is not provided. With no occurrence filters, taxa without records remain visible.

These searches use live occurrences, not periodically rebuilt statistics. Taxa occurrence
filters first build a deduplicated set of exact and reporting taxon IDs, then join it to taxa,
avoiding an occurrence scan for every taxon in the taxonomy. Annual statistics
only assign records whose date bounds fall in the same year, whereas a multi-year index search
can include an interval spanning years when the whole interval is contained.

### Taxon selection and results

Type at least two characters for suggestions matching scientific names, vernacular names or
synonyms. Suggestions show the accepted name, rank and matched synonym where applicable.
Use arrow keys and Enter to select, or Escape to dismiss. A selected taxon includes itself
and its descendants. Editing the name clears the stored selection.

Without JavaScript, enter a name and Search to load up to 20 choices in Selected taxon.
Choose one and Search again to include descendants. A name without a selection performs
name matching; general text search is also available without selecting a taxon.

Occurrence tables show readable taxon/classification/source labels, one date or date interval,
and all linked regions in alphabetical order separated by semicolons. Filtering by one region
does not hide the other regions linked to the same record. Taxa tables show one row per taxon,
its rank and configured classification columns; they do not show a misleading single record date.

Filters and sorting are preserved in pagination links and bookmarkable GET URLs. Search resets
the page number; Clear resets all filters. Invalid filters display errors rather than silently
widening the results. Downloads are not implemented in this iteration.

See [Staff search presentation](configuration-reference.md#staff-search-presentation) to choose
which classification ranks appear.

## Users

Access:

- List/view/create/edit: Admin only.
- Self-registration is disabled after initial setup.

List page:

- Columns: id, username, email, active status, groups, created_at, actions.
- Supports sort and generic search.

Create page:

- Fields: username, email, active status, groups, password, password_confirm.
- Password is required on create.

Edit page:

- Editable: username, email, active status, groups, password.
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
  - occurrences (NBN)
- report stats:
  - grid_square_stats_counts
  - taxon_rarity
  - taxon_stats
  - taxon_year_stats

Each task shows its name, source, next offset or checkpoint, completion state and action.
**Run** adds a request and attempts one batch of the oldest runnable queued work. If
another import is running, the request stays queued; it does not start in the background when
that process finishes.

Schedule `php spark import:auto` to consume queued requests automatically. Each invocation gives
admin requests priority over routine automatic imports and processes one batch. Without a
schedule, use **Process next batch now** to process existing requests without adding another task.
Refreshing the page only displays progress and never starts work or recovers runs.

Requests stay queued across successful batches until the requested work completes. Incomplete
prerequisites are listed and processed before the requested task. The current queue shows waiting,
running and failed requests, their queued time, and the start time of running work.

Failed requests stay visible with their latest run details and require **Retry** to resume from
the saved checkpoint. They are not automatically retried. **Remove from queue** discards a waiting
or failed request without deleting imported data or resetting progress. Running requests cannot
be removed. **Restart** resets progress and is available only when there is no outstanding request.

Both UI processing and `import:auto` acquire the same execution lock before selecting work.
Interrupted requests are recovered only after acquiring that lock, so a long-running import is
not marked failed merely because it exceeds a time limit. Recovery reconciles successful runs;
unfinished interrupted executions are marked failed for explicit retry.

Task outcomes and summaries are stored in `import_runs`, which is the canonical history for import
and derived-task executions.

The report-stat tasks recalculate reporting data from active occurrences:

- `grid_square_stats_counts` updates per-square occurrence counts, species counts, and rarity
  scores. See [derived grid square stats counts](import.md#derived-grid-square-stats-counts).
- `taxon_rarity` updates `taxa.rarity_category` within each `rarity_group_name`. See
  [derived taxon rarity categories](import.md#derived-taxon-rarity-categories).
- `taxon_stats` updates global and per-region records in `taxon_stats`. See
-  [derived taxon stats](import.md#derived-taxon-stats), including species frequency trends.
- `taxon_year_stats` updates configured completed-year global and per-region records in
  `taxon_year_stats`. See [derived taxon year stats](import.md#derived-taxon-year-stats).

`taxon_year_stats` must complete before `taxon_stats`, since frequency trends use its
recent annual series with missing years filled during analysis.

## Table coverage and behaviour

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

- Columns: id, rank, abbr, sort_order, reporting, actions.
- Generic search: `q` across rank, abbr, and sort_order.
- Sortable columns: id, rank, abbr, sort_order, reporting.
- Default sort: sort_order asc.
- Reporting shows whether the rank is configured as a reporting projection. This value is
  imported from the taxonomy configuration and is read-only in the admin UI.

Detail page (read-only):

- Shows id, rank, abbr, sort_order, and reporting status.

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
  classification FKs, rarity category.
- Read-only hierarchy context: current rank reporting status and the immediate accepted parent
  taxon, including its taxon identifier, scientific name, vernacular name, and rank.
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

Data sources are created during installation and are tied to import code, so the UI does not allow
staff to edit them.

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

Use the following paths when linking to an admin page or troubleshooting a request. List pages use
the plural resource name; detail and edit pages add the record ID.

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

## See also

- [Import](import.md)
- [Administration](administration.md)
- [Configuration reference](configuration-reference.md)
- [Troubleshooting](troubleshooting.md)
