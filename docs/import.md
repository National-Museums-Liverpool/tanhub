# Import

## Todo

- [] Taxon names
- [] Taxon group filter on taxon loading
- [] taxa.id_difficulty
- [] taxa.rarity_group_name default
- [] taxa blocking UI
- [] Occurrences from Elasticsearch
- [] warehouse reports into GitHub
- [] test on live warehouse (recording schemes, conservation status)
- [] UI for running spark imports

## Data sources

### iRecord

### NBN Atlas

Note that data from iRecord should not be imported, as it will be a duplicate
and the iRecord copy should be more recent. So, any records with
dataReourceName contains 'iRecord' will be ignored.

## Running the import

### Initial taxonomy setup

Empty the import_offsets table if refreshing the taxonomy. Repeat each command
until it returns "Has more: no".

```bash
$ php spark import:taxonomy --source indicia --entity recording_schemes
$ php spark import:taxonomy --source indicia --entity taxon_groups
$ php spark import:taxonomy --source indicia --entity taxon_ranks
$ php spark import:taxonomy --source indicia --entity taxa
```

Mandatory parameters:
- `--source indicia` to specify the type of server that will provide taxonomy
  data. Currently only supports indicia.
- `--entity n` - set to the name of the entity that you want to import, as per
  the examples above.

Optional parameters:
- `--dry-run` to fetch data but not load it into TanHub.
- `--limit n` to override the default limit of 5000 records per fetch.
- `--offset n` to override the offset

Notes

Taxonomic hierarchy is populated through dynamic `<rank>_id` fields on `taxa`
and `occurrences`, based on configured import ranks (for example
`kingdom_id`, `class_id`, `family_id`).

For taxa imports, load related lookup data first (`recording_schemes`,
`taxon_ranks` and `taxon_groups` at minimum), otherwise taxa rows may be
skipped due to missing foreign key mappings.