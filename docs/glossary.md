# Glossary

## <a name="reporting-taxon"></a>Reporting taxon

A reporting taxon is a taxon whose [rank](#taxon-rank) is listed in the `import.taxonRanks`
configuration setting. Reporting ranks provide a controlled set of levels, such as species, genus
and family, at which occurrences can be grouped consistently. For example, a subspecies can be
grouped so that it appears in reports for the associated species, or all ranks below family can be
grouped into a report for that family. An exact taxon is the accepted taxon concept attached to an
occurrence as it was recorded and it may be any taxon rank supported the source database from which
taxonomy is obtained, such as a species, subspecies, hybrid or species aggregate. The exact taxon
is retained; the mapping to reporting taxa does not replace it.

Without projections to reporting ranks, reports can become fragmented across subspecies, hybrids,
aggregates and other source-specific ranks. Configured ranks provide predictable aggregation levels
while preserving exact identity.

Reporting taxa are represented by rows in the `taxa` table where the `taxon_rank_id` points to a
[reporting taxon rank](#reporting-taxon-rank).

## <a name="reporting-taxon-rank"></a>Reporting taxon rank

See [Reporting taxon](#reporting-taxon). A row in the `taxon_ranks` table with `is_reporting` = 1.

## <a name="taxon-rank"></a>Taxon rank

Any level in the taxonomic hierarchy, e.g. Kingdom, Class, Order, Family, Genus, Species,
Subspecies, represented by a row in the `taxon-ranks` table.