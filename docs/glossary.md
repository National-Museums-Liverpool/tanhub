# Glossary

## <a name="reporting-taxon"></a>Reporting taxon

A reporting taxon has a [rank](#taxon-rank) listed in `import.taxonRanks`. These ranks provide
consistent grouping levels such as species, genus, and family.

An exact taxon is the accepted taxon concept attached to an occurrence as it was recorded. It can
be any rank supplied by the source, including a species, subspecies, hybrid, or species aggregate.
Tanhub keeps that exact identity and also maps the occurrence to configured reporting taxa.

For example, a subspecies can appear in the species report for its parent species. A taxon below
family can also contribute to a family report.

Without projections to reporting ranks, reports can become fragmented across subspecies, hybrids,
aggregates and other source-specific ranks. Configured ranks provide predictable aggregation levels
while preserving exact identity.

Reporting taxa are represented by rows in the `taxa` table where the `taxon_rank_id` points to a
[reporting taxon rank](#reporting-taxon-rank).

## <a name="reporting-taxon-rank"></a>Reporting taxon rank

See [Reporting taxon](#reporting-taxon). It is a row in `taxon_ranks` where `is_reporting` = 1.

## <a name="taxon-rank"></a>Taxon rank

Any level in the taxonomic hierarchy, such as Kingdom, Class, Order, Family, Genus, Species, or
Subspecies. Each rank is represented by a row in the `taxon_ranks` table.