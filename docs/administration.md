# Administering a tanhub server

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