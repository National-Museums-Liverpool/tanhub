# Import

## Data sources

### iRecord

### NBN Atlas

Occurrence imports from NBN Atlas use the records web service search endpoint:

- `https://records-ws.nbnatlas.org/occurrences/search`

Requests use `q=*:*`, paged by `start` and `pageSize`, with filters built from import
configuration:

- geographic regions are applied as a `cl254` filter from `import.geographicRegions`
- the minimum NBN rank is applied as `taxonRankID:[<minimum> TO *]` from
  `import.nbnMinTaxonRankId`
- optional custom NBN clauses are appended from `import.nbnApiFilterQuery`

`import.taxonRanks` controls the taxonomic hierarchy columns available in the local database and
does not itself create an NBN API filter. `import.nbnMinTaxonRankId` controls the lower rank bound
for NBN records.

`import.nbnApiFilterQuery` accepts either a single fq clause such as:

- `kingdom:Animalia`

or an API-style repeated fq fragment such as:

- `fq=kingdom:Animalia&fq=-phylum:Chordata&fq=-order:Lepidoptera`

NBN records with unresolved assertions are excluded server-side using:

- `-(user_assertions:"50005" OR user_assertions:"50006" OR user_assertions:"50001")`

Taxonomy identifiers are mapped as follows:

- `taxonConceptID` -> `taxa.scientific_name_identifier`
- `taxonConceptID` -> `taxon_names.given_name_identifier`

`taxonConceptID` is not matched to `taxa.taxon_identifier`, which stores the UKSI
`organism_key`.

For standard NBN records, `occurrenceID` is used when present; otherwise `uuid` is used. The
selected identifier builds the `NBN:<identifier>` unique key.

Special iRecord ownership rule for NBN payloads:

- if `occurrenceID` is integer-only, `dataProviderName` is exactly `Biological Records
  Centre`, and `dataResourceName` contains `iRecord` (case-insensitive), then the canonical
  occurrence key is `IREC:<occurrenceID>`
- NBN import inserts or updates this fallback row while its `data_source_id` is NBN
- once iRecord import upserts the same key and sets `data_source_id` to IREC, later NBN copies
  are skipped

### Indicia occurrences API

Occurrence imports from Indicia are fetched from a warehouse REST API endpoint
that proxies Elasticsearch occurrence documents. This importer does not use
Indicia report XML endpoints.

The importer uses these settings from `Config\\Import`:

- `indiciaWarehouseUrl`
- `indiciaOccurrencesEsEndpoint`
- `indiciaProjId`
- `indiciaUsername`
- `indiciaSecret`

Configured taxonomic and geographic filters are applied to occurrence requests
using the same configuration used elsewhere in import:

- `taxonGroups`
- `taxonRanks`
- `geographicRegions`
- `geographicRegionLocationType`

By default, the Indicia occurrence checkpoint uses `metadata.tracking` so
incremental loads can resume deterministically.

NBN occurrence checkpoints use the Atlas `start` offset. The last checkpoint is stored in
`import_offsets.next_checkpoint` using the source key `nbn-occurrences:occurrences`, and the next
run resumes from that value. A new NBN source starts at `0`. Use `--since` to override the stored
checkpoint for one run. Both NBN and Indicia occurrence checkpoints are written only for
non-dry-run imports.

Note that data from iRecord should not be imported from Indicia, as it will be a duplicate and the
iRecord copy should be more recent. Records whose `dataResourceName` contains `iRecord` are
ignored.

## Running the imports using the admin user interface

You can run imports by logging into the admin user interface and visiting the Imports page via the
menu option. Imports are listed along with a Go button for initiating an import batch. Imports are
limited to a batch of 5000 records so you may need to run each import several times to get it to
completion. Where an import requires another import to complete before it can be run, the blocking
import tasks are shown.

The following imports are for simple population of lookup tables and should not need to be run
again after completion:
* `recording_schemes`
* `geographic_regions`
* `grid_square_stats`
* `taxon_groups`
* `taxon_ranks`
* `taxa`
* `taxon_names`

The `grid_square_stats` task populates the `grid_square_stats` with all the 2km grid squares that
intersect your geographic regions. It does not do the actual counting of contained records.
Therefore it can also be completed once and not run again.

The 2 `occurrences` imports are for importing data from the NBN Atlas and Indicia and can be
initially run to completion, then periodically run afterwards to pick up updates and new records.
It can be run from the command-line or from cron if you want to automate this as a background
process (see below).

The remaining stats related imports are for processing the already collected internal data for
reporting outputs, e.g. count occurrence data per grid square or year. They should be run after
any modification of the occurrence data using the `occurrences` imports.

The admin Imports page queue is transient and shows only active tasks. For execution history,
status, and run summaries, use the `import_runs` table.

## Running the import using CodeIgniter Spark commands

You may prefer to use command-line Spark commands to run the import, which can be useful for script
automation or running imports via Cron.

### Initial Indicia setup

For a new installation, run the automatic task repeatedly. Each invocation runs
one bounded import batch or one report-stat task, then the next invocation selects
the next task from the current progress state:

```bash
$ php spark import:auto
```

The automatic task uses this order until the initial imports are complete:

1. recording schemes
2. geographic regions
3. grid square stats
4. taxon groups
5. taxon ranks
6. taxa
7. taxon names

After the initial imports, a report-stat task is selected when its last successful
run is more than two hours old. The least recently run stale report-stat task wins.
When all report-stat tasks are current, the least recently run occurrence source is
selected between Indicia and NBN. A task with no successful run is treated as the
oldest task.

If refreshing an import from the beginning, use the appropriate `--since=0` or `--offset=0`
override and clear the relevant `import_offsets` rows. Existing `import_runs` history may provide
backward-compatible occurrence checkpoints when no offset row exists, so use the explicit
override when restarting a source deliberately.
The individual commands remain available for targeted imports and troubleshooting:

```bash
$ php spark import:indicia --source indicia --entity recording_schemes
$ php spark import:indicia --source indicia --entity geographic_regions
$ php spark import:indicia --source indicia --entity grid_square_stats
$ php spark import:indicia --source indicia --entity taxon_groups
$ php spark import:indicia --source indicia --entity taxon_ranks
$ php spark import:indicia --source indicia --entity taxa
$ php spark import:indicia --source indicia --entity taxon_names
```

Repeat an individual command until it returns `Has more: no`.

Mandatory parameters:
- `--source indicia` to specify the type of server that will provide import
  data. Currently only supports indicia.
- `--entity n` - set to the name of the entity that you want to import, as per
  the examples above.

Optional parameters:
- `--dry-run` to fetch data but not load it into tanhub.
- `--limit n` to override the default limit of 5000 records per fetch.
- `--offset n` to override the offset

The default entity-import limit is configured by `Config\Import.defaultLimit` and defaults to
`5000`. Entity imports store their numeric progress in `import_offsets.next_offset` using keys such
as `indicia-taxonomy:taxa`.

### Occurrence imports

Import occurrences from either source:

```bash
$ php spark import:occurrences --source indicia --page-size 500 --limit 5000
$ php spark import:occurrences --source nbn --page-size 500 --limit 5000
```

Occurrence checkpoints are tracked in `import_offsets` using source keys in the form
`<source>-occurrences:occurrences` (for example `indicia-occurrences:occurrences`).

The stored occurrence checkpoint is in `import_offsets.next_checkpoint`. Indicia uses the source
record checkpoint (`metadata.tracking` by default), while NBN uses the Atlas numeric `start` offset.
Successful, failed, and interrupted non-dry-run imports preserve the latest checkpoint so a later
run can continue from the last processed point. Completion is tracked separately in
`import_offsets.is_complete`.

Optional parameters:

- `--dry-run` fetch and validate records without writing to `occurrences`.
- `--since` override source checkpoint for a run.

The occurrence defaults are `Config\Import.defaultLimit = 5000`,
`Config\Import.defaultPageSize = 200`, and `Config\Import.httpTimeout = 30` seconds. The command
options override these values for the current run only.

Occurrences with low geospatial precision are not imported. The default is to drop occurrences
with coordinate uncertainty greater than `10,000` metres. This can be configured with
`Config\Import.maximumCoordinateUncertaintyInMeters` in `.env`; set it to `0` to disable the
restriction. The setting is applied to the Indicia occurrence request. Coordinate-based
grid-reference generation uses each record's own uncertainty value.

#### Grid reference handling for occurrence imports

When a source record has `grid_ref_system` set to a non-OSGB value, Tanhub
regenerates `grid_ref` from the supplied latitude and longitude.

The conversion flow is:

1. read latitude/longitude as EPSG:4326 (WGS84)
2. project to EPSG:27700 (OSGB1936 / British National Grid)
3. choose a square size from coordinate uncertainty in metres
4. format an OSGB `grid_ref` at that precision

The selected square size is the smallest supported size that is greater than or equal to the
uncertainty, using:

- `1`
- `10`
- `100`
- `1000`
- `2000`
- `10000`
- `100000`

If uncertainty is missing or invalid, import falls back to `2000`.

For `2000`, the generated `grid_ref` uses DINTY tetrad format.

If a non-OSGB record cannot be converted (for example, missing or invalid coordinates), it is
skipped.

OSGB source records (`OSGB`, `OSGB1936`, `EPSG:27700`) continue to use the supplied `grid_ref`.

### Derived grid square stats counts

After both occurrence imports complete, run the derived counts task to populate
`grid_square_stats.occurrences_count`, `grid_square_stats.species_count` and
`grid_square_stats.rarity_score`:

```bash
$ php spark stats:grid-square-stats
```

Optional parameters:

- `--dry-run` compute aggregates without writing updates.

The task:

- counts active occurrences per grid square and geographic region
- counts distinct `taxa.species_id` values per grid square and geographic region
- calculates `rarity_score` from active gridded occurrences only
- treats species with `<= 100` active gridded records as qualifying rare species
- adds `(100 / total active gridded records for the species)` for each qualifying
  occurrence into the grid square and region where that occurrence falls
- stores the total as a decimal score, so split distributions retain fractional values

### Derived taxon rarity categories

After taxa and occurrence imports, run the derived rarity task to populate
`taxa.rarity_category` within each `rarity_group_name`:

```bash
$ php spark stats:taxon-rarity
```

The task:

- groups taxa by `rarity_group_name`
- counts active occurrences per taxon (`occurrences.deleted_at IS NULL` and
  `occurrences.blocked = 0`)
- counts distinct active 2km grid squares per taxon from `occurrences.grid_ref_2km`
- ranks taxa from low to high for each metric within each rarity group
- combines those ranks using configurable weights from `rarity.squareWeight`
  and `rarity.occurrenceWeight`
- assigns categories `1..5` from rarest to commonest within each rarity group

Optional parameters:

- `--dry-run` compute rarity categories without writing updates.

Configuration:

- `rarity.squareWeight` controls the contribution from unique 2km squares.
- `rarity.occurrenceWeight` controls the contribution from total active occurrences.

Example `env` overrides:

```dotenv
rarity.squareWeight = 1.0
rarity.occurrenceWeight = 1.0
```

### Derived taxon stats

After occurrence imports complete, run:

```bash
$ php spark stats:taxon-stats
```

Optional parameters:

- `--dry-run` compute results without writing updates.

The task:

- counts active occurrences per taxon globally and by geographic region
- counts distinct active 2km grid squares per taxon globally and by region
- stores first and last record date and recorder per scope
- stores first and last verified record date and recorder where
  `identification_verification_status` starts with `V`

### Derived taxon year stats

After occurrence imports complete, run:

```bash
$ php spark stats:taxon-year-stats
```

Optional parameters:

- `--dry-run` compute results without writing updates.

The task:

- counts active occurrences per taxon and year globally and by geographic
  region
- counts distinct active 2km grid squares per taxon and year globally and by
  region
- includes only a rolling ten-year window (current year and previous nine)

### Verify derived counts

After running `php spark stats:grid-square-stats`, you can verify that stored
counts and rarity scores match the expected aggregate values using the
following SQL.

Expected counts from active occurrences:

```sql
SELECT
  UPPER(o.grid_ref_2km) AS square,
  gro.geographic_region_id,
  COUNT(*) AS expected_occurrences_count,
  COUNT(DISTINCT t.species_id) AS expected_species_count
FROM occurrences o
INNER JOIN geographic_regions_occurrences gro
  ON gro.occurrence_id = o.id
INNER JOIN taxa t
  ON t.id = o.taxon_id
WHERE o.deleted_at IS NULL
  AND o.blocked = 0
  AND o.grid_ref_2km IS NOT NULL
  AND TRIM(o.grid_ref_2km) <> ''
GROUP BY UPPER(o.grid_ref_2km), gro.geographic_region_id
ORDER BY square, gro.geographic_region_id;
```

Rows where stored counts differ from expected counts:

```sql
SELECT
  gss.square,
  gss.geographic_region_id,
  gss.occurrences_count AS stored_occurrences_count,
  COALESCE(exp.expected_occurrences_count, 0) AS expected_occurrences_count,
  gss.species_count AS stored_species_count,
  COALESCE(exp.expected_species_count, 0) AS expected_species_count
FROM grid_square_stats gss
LEFT JOIN (
  SELECT
    UPPER(o.grid_ref_2km) AS square,
    gro.geographic_region_id,
    COUNT(*) AS expected_occurrences_count,
    COUNT(DISTINCT t.species_id) AS expected_species_count
  FROM occurrences o
  INNER JOIN geographic_regions_occurrences gro
    ON gro.occurrence_id = o.id
  INNER JOIN taxa t
    ON t.id = o.taxon_id
  WHERE o.deleted_at IS NULL
    AND o.blocked = 0
    AND o.grid_ref_2km IS NOT NULL
    AND TRIM(o.grid_ref_2km) <> ''
  GROUP BY UPPER(o.grid_ref_2km), gro.geographic_region_id
) exp
  ON exp.square = gss.square
  AND exp.geographic_region_id = gss.geographic_region_id
WHERE gss.occurrences_count <> COALESCE(exp.expected_occurrences_count, 0)
  OR gss.species_count <> COALESCE(exp.expected_species_count, 0)
ORDER BY gss.square, gss.geographic_region_id;
```

Expected rarity scores from active gridded occurrences:

```sql
SELECT
  square_species.square,
  square_species.geographic_region_id,
  ROUND(
    SUM((100.0 / species_totals.total_records) * square_species.square_occurrences_count),
    4
  ) AS expected_rarity_score
FROM (
  SELECT
    UPPER(o.grid_ref_2km) AS square,
    gro.geographic_region_id,
    t.species_id,
    COUNT(*) AS square_occurrences_count
  FROM occurrences o
  INNER JOIN geographic_regions_occurrences gro
    ON gro.occurrence_id = o.id
  INNER JOIN taxa t
    ON t.id = o.taxon_id
  WHERE o.deleted_at IS NULL
    AND o.blocked = 0
    AND o.grid_ref_2km IS NOT NULL
    AND TRIM(o.grid_ref_2km) <> ''
    AND t.species_id IS NOT NULL
  GROUP BY UPPER(o.grid_ref_2km), gro.geographic_region_id, t.species_id
) square_species
INNER JOIN (
  SELECT
    t.species_id,
    COUNT(*) AS total_records
  FROM occurrences o
  INNER JOIN taxa t
    ON t.id = o.taxon_id
  WHERE o.deleted_at IS NULL
    AND o.blocked = 0
    AND o.grid_ref_2km IS NOT NULL
    AND TRIM(o.grid_ref_2km) <> ''
    AND t.species_id IS NOT NULL
  GROUP BY t.species_id
  HAVING COUNT(*) <= 100
) species_totals
  ON species_totals.species_id = square_species.species_id
GROUP BY square_species.square, square_species.geographic_region_id
ORDER BY square_species.square, square_species.geographic_region_id;
```

Rows where stored rarity scores differ from expected rarity scores:

```sql
SELECT
  gss.square,
  gss.geographic_region_id,
  COALESCE(gss.rarity_score, 0) AS stored_rarity_score,
  COALESCE(exp.expected_rarity_score, 0) AS expected_rarity_score
FROM grid_square_stats gss
LEFT JOIN (
  SELECT
    square_species.square,
    square_species.geographic_region_id,
    ROUND(
      SUM((100.0 / species_totals.total_records) * square_species.square_occurrences_count),
      4
    ) AS expected_rarity_score
  FROM (
    SELECT
      UPPER(o.grid_ref_2km) AS square,
      gro.geographic_region_id,
      t.species_id,
      COUNT(*) AS square_occurrences_count
    FROM occurrences o
    INNER JOIN geographic_regions_occurrences gro
      ON gro.occurrence_id = o.id
    INNER JOIN taxa t
      ON t.id = o.taxon_id
    WHERE o.deleted_at IS NULL
      AND o.blocked = 0
      AND o.grid_ref_2km IS NOT NULL
      AND TRIM(o.grid_ref_2km) <> ''
      AND t.species_id IS NOT NULL
    GROUP BY UPPER(o.grid_ref_2km), gro.geographic_region_id, t.species_id
  ) square_species
  INNER JOIN (
    SELECT
      t.species_id,
      COUNT(*) AS total_records
    FROM occurrences o
    INNER JOIN taxa t
      ON t.id = o.taxon_id
    WHERE o.deleted_at IS NULL
      AND o.blocked = 0
      AND o.grid_ref_2km IS NOT NULL
      AND TRIM(o.grid_ref_2km) <> ''
      AND t.species_id IS NOT NULL
    GROUP BY t.species_id
    HAVING COUNT(*) <= 100
  ) species_totals
    ON species_totals.species_id = square_species.species_id
  GROUP BY square_species.square, square_species.geographic_region_id
) exp
  ON exp.square = gss.square
  AND exp.geographic_region_id = gss.geographic_region_id
WHERE ABS(COALESCE(gss.rarity_score, 0) - COALESCE(exp.expected_rarity_score, 0)) > 0.0001
ORDER BY gss.square, gss.geographic_region_id;
```

## Notes

Taxonomic hierarchy is populated through dynamic `<rank>_id` fields on `taxa`
and `occurrences`, based on configured import ranks (for example
`kingdom_id`, `class_id`, `family_id`).

`import.taxonRanks` must include `Species` so `species_id` is always present
for derived species counts.

For taxa imports, load related lookup data first (`recording_schemes`,
`taxon_ranks` and `taxon_groups` at minimum), otherwise taxa rows may be
skipped due to missing foreign key mappings.

For taxon group imports, groups are imported from the database if they are in the list of
configured groups, or if they belong to a taxon that belongs to one of the configured taxonomic
ranks and that is a parent of a taxon in one of the configured groups. For example, if you import
terrestrial mammals and include Kingdom in your ranks, then you may also see "Unassigned" appear
in your list of groups because this is the group required to store Kingdom Animalia. Such taxon
group records have their `implied` flag set to 1.

For grid square stats imports, the `grid_squares.xml` report uses the same
`geographic_regions` and `location_type` parameters as
`geographic_regions.xml`. It fills `uuid`, `square`, `geographic_region_id`,
`easting`, `northing`, `lat`, `lon`, and `partial`; the counts are filled by
the separate grid-square counts task.

The grid-square counts task uses active occurrences only
(`occurrences.deleted_at IS NULL` and `occurrences.blocked = 0`) and aggregates
by `(grid_ref_2km, geographic_region_id)`. `species_count` is calculated as a
distinct count of `taxa.species_id` values linked by `occurrences.taxon_id`.
`rarity_score` sums weighted qualifying occurrences, where a qualifying species
has `<= 100` active gridded records across the full dataset and each occurrence
contributes `100 / total_records_for_species` to its square and region.

Configure the taxon groups that will be imported in your `env` file's `import.taxonGroups`
setting. Configure the reporting hierarchy separately with `import.taxonRanks`; it must include
`Species`.

The importer is designed to stop on an error, allowing you to diagnose, fix
then restart the process from where it left off.

Dependencies:
You cannot import `grid_square_stats` until the following imports are completed:
- `geographic_regions`
You cannot import `taxa` until the following imports are completed:
- `recording_schemes`
- `geographic_regions`
- `taxon_groups`
- `taxon_ranks`
You cannot import `taxon_names` until the following imports are completed:
- `taxa`
You cannot import `occurrences` until the following imports are completed:
- `recording_schemes`
- `geographic_regions`
- `grid_square_stats`
- `taxon_groups`
- `taxon_ranks`
- `taxa`
- `taxon_names`
You cannot run `grid_square_stats_counts` until the following imports are completed and statistics
will be based on loaded occurrences only:
- `occurrence:indicia:occurrences`
- `occurrence:nbn:occurrences`

You cannot run `taxon_rarity` until the following imports are completed  and statistics will be
based on loaded occurrences only:
- `taxa`

An import task is marked as complete when it is successfully run and returns has more: no.

## See also

- [Installation](installation.md)
- [Admin user interface](admin-ui.md)
- [Configuration reference](configuration-reference.md)
- [Database schema](database.md)
- [Troubleshooting](troubleshooting.md)