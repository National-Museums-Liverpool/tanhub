# Import

## Data sources

### iRecord

### NBN Atlas

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

Note that data from iRecord should not be imported, as it will be a duplicate
and the iRecord copy should be more recent. So, any records with
dataReourceName contains 'iRecord' will be ignored.

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

## Running the import using CodeIgniter Spark commands

You may prefer to use command-line Spark commands to run the import, which can be useful for script
automation or running imports via Cron.

### Initial Indicia setup

Empty the import_offsets table if refreshing the import. Repeat each command
until it returns "Has more: no".

```bash
$ php spark import:indicia --source indicia --entity recording_schemes
$ php spark import:indicia --source indicia --entity geographic_regions
$ php spark import:indicia --source indicia --entity grid_square_stats
$ php spark import:indicia --source indicia --entity taxon_groups
$ php spark import:indicia --source indicia --entity taxon_ranks
$ php spark import:indicia --source indicia --entity taxa
$ php spark import:indicia --source indicia --entity taxon_names
```

Mandatory parameters:
- `--source indicia` to specify the type of server that will provide import
  data. Currently only supports indicia.
- `--entity n` - set to the name of the entity that you want to import, as per
  the examples above.

Optional parameters:
- `--dry-run` to fetch data but not load it into tanhub.
- `--limit n` to override the default limit of 5000 records per fetch.
- `--offset n` to override the offset

### Occurrence imports

Import occurrences from either source:

```bash
$ php spark import:occurrences --source indicia --page-size 500 --limit 5000
$ php spark import:occurrences --source nbn --page-size 500 --limit 5000
```

Occurrence checkpoints are tracked in `import_offsets` using source keys in the form `<source>-occurrences:occurrences` (for example `indicia-occurrences:occurrences`).

Optional parameters:

- `--dry-run` fetch and validate records without writing to `occurrences`.
- `--since` override source checkpoint for a run.

### Derived grid square stats counts

After occurrence imports, run the derived counts task to populate
`grid_square_stats.occurrences_count` and `grid_square_stats.species_count`:

```bash
$ php spark stats:grid-square-stats
```

Optional parameters:

- `--dry-run` compute aggregates without writing updates.

### Verify derived counts

After running `php spark stats:grid-square-stats`, you can verify that stored
counts match the expected aggregate values using the following SQL.

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

Configure the taxon groups that will be imported in your `env` file's
`import.taxonRanks` setting.

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
You cannot run `grid_square_stats_counts` until the following imports are
completed:
- `grid_square_stats`
- `occurrences` (indicia)
- `occurrences` (nbn)

An import task is marked as complete when it is successfully run and returns has more: no.