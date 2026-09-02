# Administering a tanhub server

## Bulk taxon media import

Staff can stage a CSV and its image files from **Taxonomy > Bulk media import**. The upload request
only validates and stages the work. Processing happens in a resumable Spark worker so large photo
sets do not need to complete in one web request.

The CSV must contain exactly one of these identifier columns:

- `taxon_identifier`
- `scientific_name_identifier`
- `scientific_name`

It must also contain `photo_filename`. The optional columns are `alt_text`, `caption`,
`attribution`, `license`, `sort_order`, and `is_primary`.

`photo_filename` is matched against uploaded file basenames with case sensitivity. Every CSV file
must have exactly one matching upload, with no duplicate, missing, or extra files. Taxa are matched
directly first. If there is no direct match, identifier columns use
`taxon_names.given_name_identifier` and `scientific_name` uses `taxon_names.name`; exactly one
accepted-name taxon must result.

The page processes queued photos automatically, one at a time, and updates the progress display
after each photo. Processing pauses safely if the page closes.

To resume queued imports from the command line, run:

```bash
php spark taxon-media:work
```

The command selects the oldest queued import. To process a specific import, use the numeric import
ID shown in the **Your recent imports** table:

```bash
php spark taxon-media:work --import-id=123
```

Use `--limit` to set the maximum number of photos in each worker batch. Run the same command again
to continue from its checkpoint. Rows remain hidden from normal media reads until every row has
processed and the import publishes atomically. When multiple rows for a taxon have `is_primary=1`,
the last such row in CSV order wins and clears any existing primary for that taxon.

## Rebuild taxon media variants

If you need to change the configuration for taxon media variants, e.g. thumbnails, you can rebuild
them as follows.

Usage:

Dry run all:
```bash
php spark media:rebuild-taxon-media-variants --dry-run
```
Rebuild all:
```bash
php spark media:rebuild-taxon-media-variants
```
Rebuild one taxon:
```bash
php spark media:rebuild-taxon-media-variants --taxon-id=12444
```
Rebuild one media row:
```bash
php spark media:rebuild-taxon-media-variants --media-id=123
```

## Limit original upload dimensions

To reduce disk usage on shared hosting, you can cap the dimensions of stored original images.
Oversized uploads will be downscaled to fit inside the configured width/height while preserving
aspect ratio.

Set in .env:

```ini
taxonMedia.maxOriginalWidth = 2048
taxonMedia.maxOriginalHeight = 2048
```

Set both values greater than 0 to enable downscaling. If either value is omitted (or 0), the
original image is stored at uploaded dimensions.

# Clear the caches

```bash
php spark cache:clear
```

## See also

- [Configuration reference](configuration-reference.md)
- [Admin user interface](admin-ui.md)
- [Troubleshooting](troubleshooting.md)