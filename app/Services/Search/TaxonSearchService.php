<?php

namespace App\Services\Search;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\TaxonSearch;
use RuntimeException;

/**
 * Reusable live queries and batch row preparation for staff indexes.
 *
 * Occurrence rows keep the exact recorded taxon; taxa rows can qualify through their
 * configured reporting descendants. Live records, not asynchronously rebuilt statistics,
 * are used so contained multi-year intervals have consistent search semantics.
 *
 * {@see self::query()} builds predicates without pagination, {@see self::hydrate()}
 * prepares any result batch, and {@see self::page()} adapts these to the staff views.
 * Keep this separation so future downloads can reuse the same filtering rules.
 */
final class TaxonSearchService
{
    /** @var BaseConnection Database used by all search queries. */
    private BaseConnection $db;

    /** @var array<string, array<string, mixed>> Installed reporting ranks by safe alias. */
    private array $ranks = [];

    /** @var array<string, array<string, mixed>> Visible classification ranks by safe alias. */
    private array $classifications = [];

    /**
     * Resolve reporting metadata and validate presentation configuration.
     *
     * Reporting columns are installation-specific. Only installed, normalized aliases
     * may become SQL identifiers; request values never define column names.
     *
     * @param BaseConnection|null $db Explicit connection for bounded export transactions.
     * @throws RuntimeException If a non-default classification setting is unavailable.
     */
    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? db_connect();
        $configured = config('Import')->taxonRanks;
        $configured = is_array($configured) ? $configured : explode(',', $configured);
        $metadata = $this->db->table('taxon_ranks')->where('deleted_at', null)
            ->orderBy('sort_order')->get()->getResultArray();
        foreach ($configured as $rank) {
            $alias = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $rank))), '_');
            if ($alias === '' || ! $this->db->fieldExists($alias . '_id', 'taxa')
                || ! $this->db->fieldExists($alias . '_id', 'occurrences')) {
                log_message('warning', 'Search reporting rank has missing columns: {rank}', ['rank' => $rank]);
                continue;
            }
            foreach ($metadata as $row) {
                if (strcasecmp($row['rank'], trim((string) $rank)) === 0) {
                    $this->ranks[$alias] = $row;
                    break;
                }
            }
        }
        $visible = config(TaxonSearch::class)->classificationRanks;
        $visible = is_array($visible) ? $visible : explode(',', $visible);
        foreach ($visible as $rank) {
            if (trim((string) $rank) === '') {
                continue;
            }
            $alias = trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim((string) $rank))), '_');
            if (! isset($this->ranks[$alias])) {
                if ($visible === ['Family'] || $visible === ['family']) {
                    log_message('warning', 'Default Family search filter omitted: reporting rank is not available.');
                    continue;
                }
                log_message('error', 'Invalid taxonSearch.classificationRanks entry: {rank}', ['rank' => $rank]);
                throw new RuntimeException('Search classification rank is unavailable: ' . $rank);
            }
            $this->classifications[$alias] = $this->ranks[$alias];
        }
    }

    /**
     * Parse filters and verify references against live, non-deleted data.
     *
     * Classification selections must have the indicated actual rank, independently
     * of the taxa checklist's requested Rank. Blocked choices remain valid for staff.
     *
     * @param array<string, mixed> $input Submitted parameters.
     * @param bool $taxa Whether this is a taxa search.
     * @return SearchCriteria Validated criteria with explicit errors.
     */
    public function criteria(array $input, bool $taxa): SearchCriteria
    {
        $criteria = new SearchCriteria($input, array_keys($this->classifications), $taxa);
        $references = ['taxon_id' => 'taxa', 'taxon_group_id' => 'taxon_groups',
            'geographic_region_id' => 'geographic_regions', 'data_source_id' => 'data_sources'];
        foreach ($references as $key => $table) {
            if ($criteria->value($key) !== '' && ! isset($criteria->errors[$key])) {
                $reference = $this->db->table($table)->where('id', $criteria->value($key));
                if ($table !== 'data_sources') {
                    $reference->where('deleted_at', null);
                }
                if ($reference->countAllResults() === 0) {
                    $criteria->errors[$key] = 'This option is no longer available.';
                }
            }
        }
        if ($taxa && $criteria->value('rank_id') !== ''
            && ! in_array($criteria->value('rank_id'), array_map(
                static fn (array $rank): string => (string) $rank['id'], $this->ranks
            ), true)) {
            $criteria->errors['rank_id'] = 'Choose an available reporting rank.';
        }
        foreach ($this->classifications as $alias => $rank) {
            $key = $alias . '_id';
            if ($criteria->value($key) !== '' && ! isset($criteria->errors[$key])
                && $this->db->table('taxa')->where('id', $criteria->value($key))
                    ->where('taxon_rank_id', $rank['id'])->where('deleted_at', null)->countAllResults() === 0) {
                $criteria->errors[$key] = 'Choose a taxon of the indicated classification rank.';
            }
        }

        return $criteria;
    }

    /**
     * Construct an unpaginated filtered query for either index.
     *
     * Taxonomic predicates constrain the displayed taxon; occurrence predicates constrain
     * a single contributing record. Ordering belongs to the caller, not this reusable query.
     * Criteria with errors produce no rows, leaving the caller to display those errors.
     *
     * @param SearchCriteria $criteria Validated search filters.
     * @param bool $taxa Whether to return taxa rather than occurrences.
     * @param list<int|string>|null $candidateIds Optional bounded export candidate IDs.
     * @param bool $idsOnly Select matching IDs without unnecessary display joins.
     * @return BaseBuilder Query usable for counting, pagination or future batch output.
     */
    public function query(SearchCriteria $criteria, bool $taxa, ?array $candidateIds = null, bool $idsOnly = false): BaseBuilder
    {
        $builder = $taxa ? $this->db->table('taxa t') : $this->db->table('occurrences o');
        $lookupOnly = false;
        if (! $taxa) {
            $taxonFilters = array_intersect_key($criteria->values, array_flip(
                ['q', 'taxon_group_id', 'taxon_blocked', 'taxon_id', 'taxon_name']
            ));
            $lookupOnly = $idsOnly && $this->db->DBDriver === 'MySQLi'
                && array_filter($taxonFilters, static fn (string $value): bool => $value !== '') === [];
            if ($lookupOnly) {
                // Prevent a buffered taxon join from sorting the entire ID range before LIMIT.
                $aliases = $this->db->aliasedTables;
                $table = $this->db->escapeIdentifiers($this->db->prefixTable('taxa'));
                $builder->join($table . ' t FORCE INDEX (PRIMARY)',
                    't.id = o.taxon_id AND t.deleted_at IS NULL', 'inner', false);
                $this->db->setAliasedTables(array_merge($aliases, ['t']));
            } else {
                $builder->join('taxa t', 't.id = o.taxon_id AND t.deleted_at IS NULL');
            }
            $builder->where('o.deleted_at', null);
            if (! $idsOnly || $criteria->value('q') !== '') {
                $builder->join('taxon_names recorded', 'recorded.id = o.taxon_name_id AND recorded.deleted_at IS NULL', 'left')
                    ->join('data_sources ds', 'ds.id = o.data_source_id', 'left');
            }
        }
        if ($lookupOnly) {
            $builder->select('STRAIGHT_JOIN o.id', false);
        } else {
            $builder->select($idsOnly ? ($taxa ? 't.id' : 'o.id')
                : ($taxa ? 't.*' : 'o.*, t.scientific_name, t.vernacular_name, ds.title AS data_source_title'));
        }
        $builder->where('t.deleted_at', null);
        if (! $idsOnly || $criteria->value('q') !== '') {
            $builder->join('taxon_groups tg', 'tg.id = t.taxon_group_id AND tg.deleted_at IS NULL', 'left')
                ->join('taxon_ranks tr', 'tr.id = t.taxon_rank_id AND tr.deleted_at IS NULL', 'left');
        }
        if (! $idsOnly) {
            $builder->select('tr.rank AS rank_title, tr.sort_order AS rank_order, tg.title AS taxon_group_title, tg.friendly AS taxon_group_friendly');
        }
        foreach ($this->classifications as $alias => $rank) {
            $owner = $taxa ? 't' : 'o';
            if (! $idsOnly || $criteria->value('q') !== '') {
                $builder->join("taxa c_{$alias}", "c_{$alias}.id = {$owner}.{$alias}_id AND c_{$alias}.deleted_at IS NULL", 'left');
            }
            if (! $idsOnly) {
                $builder->select("c_{$alias}.scientific_name AS {$alias}_name");
            }
            if ($criteria->value($alias . '_id') !== '') {
                $builder->where("{$owner}.{$alias}_id", $criteria->value($alias . '_id'));
            }
        }
        foreach (['taxon_group_id' => 't.taxon_group_id', 'taxon_blocked' => 't.blocked'] as $key => $column) {
            if ($criteria->value($key) !== '') {
                $builder->where($column, $criteria->value($key));
            }
        }
        if ($taxa && $criteria->value('rank_id') !== '') {
            $builder->where('t.taxon_rank_id', $criteria->value('rank_id'));
        }
        if ($criteria->value('taxon_id') !== '' && ! isset($criteria->errors['taxon_id'])) {
            $this->applySubtree($builder, 't', (int) $criteria->value('taxon_id'));
        } elseif ($criteria->value('taxon_name') !== '') {
            $this->applyNameSearch($builder, $criteria->value('taxon_name'), 't');
        }
        if ($criteria->value('q') !== '') {
            $this->applyTextSearch($builder, $criteria->value('q'), $taxa);
        }
        if ($taxa && $criteria->needsOccurrences()) {
            $matches = $this->matchingTaxa($criteria, $candidateIds);
            $builder->join(
                '(' . $matches->getCompiledSelect(false) . ') matching_taxa',
                'matching_taxa.taxon_id = t.id',
                'inner',
                false
            );
        } elseif (! $taxa) {
            $this->applyOccurrenceFilters($builder, $criteria, 'o');
        }
        if ($criteria->errors !== []) {
            $builder->where('1 = 0', null, false);
        }

        return $builder;
    }

    /**
     * Build a deduplicated, uncorrelated set of exact and reporting taxon IDs.
     *
     * UNION materializes occurrence evidence independently of the outer taxa rows.
     * A correlated OR across rank columns can rescan a region's records for every taxon.
     * Do not replace this with that pattern without checking real database query plans.
     *
     * UNION, not UNION ALL, also prevents duplicates from repeated occurrences or a
     * self-referencing rank projection. The projected taxon's actual rank is checked:
     * a Species projection can point at an aggregate without making that row a species.
     *
     * @param SearchCriteria $criteria Validated occurrence and rank filters.
     * @param list<int|string>|null $candidateIds Optional bounded taxa candidates.
     * @return BaseBuilder Matching taxon IDs suitable for joining to the taxa list.
     */
    private function matchingTaxa(SearchCriteria $criteria, ?array $candidateIds = null): BaseBuilder
    {
        $evidence = $this->db->table('occurrences evidence')
            ->join('taxa exact_taxon', 'exact_taxon.id = evidence.taxon_id AND exact_taxon.deleted_at IS NULL')
            ->where('evidence.deleted_at', null);
        if ($criteria->value('taxon_blocked') === '0') {
            // Excluding blocked taxa also excludes blocked children as checklist evidence.
            // Selecting blocked parents does not require their children to be blocked.
            $evidence->where('exact_taxon.blocked', 0);
        }
        $this->applyOccurrenceFilters($evidence, $criteria, 'evidence');
        $matches = (clone $evidence)->select('evidence.taxon_id AS taxon_id');
        if ($candidateIds !== null) {
            $matches->whereIn('evidence.taxon_id', $candidateIds);
        }
        if ($criteria->value('rank_id') !== '') {
            $matches->where('exact_taxon.taxon_rank_id', $criteria->value('rank_id'));
        }
        foreach ($this->ranks as $alias => $rank) {
            if ($criteria->value('rank_id') !== '' && $criteria->value('rank_id') !== (string) $rank['id']) {
                continue;
            }
            $projection = (clone $evidence)->select("evidence.{$alias}_id AS taxon_id")
                ->join('taxa reporting_taxon', "reporting_taxon.id = evidence.{$alias}_id")
                ->where('reporting_taxon.taxon_rank_id', $rank['id'])
                ->where('reporting_taxon.deleted_at', null);
            if ($candidateIds !== null) {
                $projection->whereIn("evidence.{$alias}_id", $candidateIds);
            }
            $matches->union($projection);
        }

        return $matches;
    }

    /**
     * Prepare a paginated index while keeping query and row hydration reusable.
     *
     * The returned data supplies the shared search views: filters/errors, reference options,
     * selected taxon/suggestions, sort state, total/pager, and either taxa or occurrences.
     *
     * @param array<string, mixed> $input Submitted GET parameters.
     * @param bool $taxa Whether this is the taxa index.
     * @return array<string, mixed> View data including criteria, rows and pager.
     */
    public function page(array $input, bool $taxa): array
    {
        $criteria = $this->criteria($input, $taxa);
        $ordering = $this->ordering($input, $taxa);
        $criteria->errors += $ordering['errors'];
        $sort = $ordering['sort'];
        $direction = $ordering['direction'];
        $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($page === false) {
            $criteria->errors['page'] = 'Choose a valid page number.';
            $page = 1;
        }
        $query = $this->query($criteria, $taxa);
        // Resetting the count builder would clear shared connection aliases needed by the row query.
        $total = (clone $query)->countAllResults(false);
        $this->orderQuery($query, $ordering, $taxa);
        $rows = $query->get(20, ($page - 1) * 20)->getResultArray();
        $pager = service('pager');
        $pager->store('default', $page, 20, $total);
        $options = $this->options();
        $selected = null;
        if ($criteria->value('taxon_id') !== '' && ! isset($criteria->errors['taxon_id'])) {
            $selected = $this->db->table('taxa')->select('id, scientific_name, vernacular_name')
                ->where('id', $criteria->value('taxon_id'))->where('deleted_at', null)->get()->getRowArray();
        }
        $matches = [];
        if ($selected === null && $criteria->value('taxon_name') !== '' && $criteria->errors === []) {
            $matches = $this->lookup($criteria->value('taxon_name'), $criteria);
        }

        return array_merge($options, [
            $taxa ? 'taxa' : 'occurrences' => $this->hydrate($rows, $taxa),
            'isTaxa' => $taxa, 'filters' => $criteria->values, 'errors' => $criteria->errors,
            'q' => $criteria->value('q'), 'sort' => $sort, 'direction' => $direction,
            'pager' => $pager, 'total' => $total, 'selectedTaxon' => $selected, 'taxonMatches' => $matches,
            'filterLabels' => $this->filterLabels($taxa), 'columnLabels' => $this->columnLabels($taxa),
        ]);
    }

    /**
     * Validate the shared grid/export ordering without running the page query.
     *
     * @param array<string, mixed> $input Submitted ordering.
     * @param bool $taxa Whether this is the taxa list.
     * @return array<string, mixed> Sort key, SQL column, direction and validation errors.
     */
    public function ordering(array $input, bool $taxa): array
    {
        $sorts = $taxa
            ? ['id' => 't.id', 'taxon_identifier' => 't.taxon_identifier', 'scientific_name' => 't.scientific_name',
                'vernacular_name' => 't.vernacular_name', 'conservation_status' => 't.conservation_status',
                'taxon_group_title' => 'tg.title', 'rank_title' => 'tr.sort_order', 'blocked' => 't.blocked']
            : ['id' => 'o.id', 'unique_key' => 'o.unique_key', 'from_date' => 'o.from_date', 'to_date' => 'o.to_date',
                'grid_ref' => 'o.grid_ref', 'data_source_id' => 'o.data_source_id', 'blocked' => 'o.blocked',
                'taxon_id' => 'o.taxon_id', 'taxon_name_id' => 'o.taxon_name_id',
                'scientific_name' => 't.scientific_name', 'vernacular_name' => 't.vernacular_name',
                'taxon_group_title' => 'tg.title', 'data_source_title' => 'ds.title'];
        foreach ($this->classifications as $alias => $rank) {
            $sorts[$alias . '_name'] = 'c_' . $alias . '.scientific_name';
        }
        $sort = $taxa ? 'scientific_name' : 'from_date';
        $direction = $taxa ? 'asc' : 'desc';
        $errors = [];
        foreach (['sort', 'direction'] as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $value = is_string($input[$key]) ? strtolower($input[$key]) : '';
                if (($key === 'sort' && isset($sorts[$value]))
                    || ($key === 'direction' && in_array($value, ['asc', 'desc'], true))) {
                    if ($key === 'sort') {
                        $sort = $value;
                    } else {
                        $direction = $value;
                    }
                } else {
                    $errors[$key] = 'Choose a valid sort option.';
                }
            }
        }
        return ['sort' => $sort, 'direction' => $direction, 'column' => $sorts[$sort], 'errors' => $errors];
    }

    /**
     * Apply the grid's complete deterministic ordering.
     *
     * @param BaseBuilder $query Filtered query.
     * @param array<string, mixed> $ordering Validated ordering.
     * @param bool $taxa Whether to order taxa.
     */
    public function orderQuery(BaseBuilder $query, array $ordering, bool $taxa): void
    {
        $query->orderBy($ordering['column'], $ordering['direction']);
        if (! $taxa && $ordering['sort'] === 'from_date') {
            $query->orderBy('o.to_date', $ordering['direction']);
        }
        $query->orderBy($taxa ? 't.id' : 'o.id', $ordering['direction']);
    }

    /**
     * Return configured visible classifications for a frozen export contract.
     *
     * @return array<string, array<string, mixed>> Classification rank metadata.
     */
    public function classifications(): array
    {
        return $this->classifications;
    }

    /**
     * Share filter captions between search controls and export confirmation.
     *
     * @param bool $taxa Whether the controls belong to the taxa list.
     * @return array<string, string> Filter keys and user-facing captions.
     */
    public function filterLabels(bool $taxa): array
    {
        $labels = ['q' => 'Search any descriptive field', 'year' => 'Year',
            'geographic_region_id' => 'Geographic region', 'taxon_group_id' => 'Taxon group',
            'taxon_name' => 'Taxon (including descendants)', 'taxon_id' => 'Selected taxon',
            'data_source_id' => $taxa ? 'Data source (matching records)' : 'Data source',
            'taxon_blocked' => 'Taxon blocked', 'blocked' => 'Occurrence blocked',
            'from_date' => 'From date', 'to_date' => 'To date'];
        if ($taxa) {
            $labels['rank_id'] = 'Rank';
        }
        foreach ($this->classifications as $alias => $rank) {
            $labels[$alias . '_id'] = $rank['rank'];
        }
        return $labels;
    }

    /**
     * Share grid column captions with export ordering summaries.
     *
     * @param bool $taxa Whether displaying taxa rather than occurrences.
     * @return array<string, string> Visible grid columns in display order.
     */
    public function columnLabels(bool $taxa): array
    {
        $columns = $taxa
            ? ['scientific_name' => 'Scientific name', 'vernacular_name' => 'Vernacular name',
                'rank_title' => 'Rank', 'taxon_group_title' => 'Taxon group']
            : ['from_date' => 'Date', 'scientific_name' => 'Scientific name',
                'vernacular_name' => 'Vernacular name', 'taxon_group_title' => 'Taxon group'];
        foreach ($this->classifications as $alias => $rank) {
            $columns[$alias . '_name'] = $rank['rank'];
        }
        return $columns + ($taxa
            ? ['conservation_status' => 'Conservation status', 'taxon_identifier' => 'Taxon identifier',
                'blocked' => 'Blocked']
            : ['regions' => 'Geographic regions', 'grid_ref' => 'Grid ref', 'data_source_title' => 'Data source',
                'unique_key' => 'Unique key', 'blocked' => 'Blocked']);
    }

    /**
     * Resolve applied criteria to the same labels and selected captions as search controls.
     *
     * @param SearchCriteria $criteria Validated filters with any visible errors.
     * @param array<string, mixed> $ordering Validated grid ordering.
     * @param bool $taxa Whether summarising the taxa list.
     * @return list<array{label: string, value: string}> Human-readable non-empty filters and ordering.
     */
    public function summary(SearchCriteria $criteria, array $ordering, bool $taxa): array
    {
        $labels = $this->filterLabels($taxa);
        $references = ['geographic_region_id' => ['geographic_regions', 'higher_geography'],
            'taxon_group_id' => ['taxon_groups', 'title'], 'taxon_id' => ['taxa', 'scientific_name'],
            'data_source_id' => ['data_sources', 'title'], 'rank_id' => ['taxon_ranks', 'rank']];
        foreach ($this->classifications as $alias => $rank) {
            $references[$alias . '_id'] = ['taxa', 'scientific_name'];
        }
        $summary = [];
        foreach ($criteria->values as $key => $value) {
            if ($value === '') {
                continue;
            }
            if (isset($references[$key])) {
                [$table, $field] = $references[$key];
                $row = isset($criteria->errors[$key]) ? null : $this->db->table($table)
                    ->where('id', $value)->get()->getRowArray();
                if ($row === null) {
                    $value = 'Unavailable selection';
                } elseif ($key === 'taxon_group_id' && ! empty($row['friendly'])) {
                    $value = $row['friendly'];
                } elseif ($key === 'taxon_id') {
                    $value = $row['scientific_name'] . (empty($row['vernacular_name'])
                        ? '' : ' - ' . $row['vernacular_name']);
                } else {
                    $value = $row[$field];
                }
            } elseif (in_array($key, ['blocked', 'taxon_blocked'], true)
                && ! isset($criteria->errors[$key])) {
                $value = $value === '1' ? 'Yes' : 'No';
            }
            $summary[] = ['label' => $labels[$key], 'value' => (string) $value];
        }
        $sortLabels = $this->columnLabels($taxa) + ['id' => $taxa ? 'Taxon ID' : 'Occurrence ID',
            'to_date' => 'To date', 'data_source_id' => 'Data source ID', 'taxon_id' => 'Taxon ID',
            'taxon_name_id' => 'Recorded name ID'];
        $summary[] = ['label' => 'Sort by', 'value' => $sortLabels[$ordering['sort']]];
        $summary[] = ['label' => 'Direction', 'value' => $ordering['direction'] === 'asc' ? 'Ascending' : 'Descending'];
        return $summary;
    }

    /**
     * Retrieve bounded, deduplicated taxon-name suggestions.
     *
     * Suggest accepted taxa even when a synonym matched. Ignore checklist Rank and
     * occurrence scope so a higher taxon remains selectable as a subtree root.
     * The 20-row cap also bounds the non-JavaScript selection list.
     *
     * @param string $term Scientific, vernacular or synonym query.
     * @param SearchCriteria $criteria Contextual classification and moderation filters.
     * @return list<array{id: int, scientific_name: string, vernacular_name: string, rank: string, matched_name: string}>
     *     Suggested taxa using the JSON contract consumed by taxon-search.js.
     */
    public function lookup(string $term, SearchCriteria $criteria): array
    {
        $values = $criteria->values;
        foreach (['q', 'taxon_name', 'taxon_id', 'rank_id', 'year', 'from_date', 'to_date',
            'geographic_region_id', 'data_source_id', 'blocked'] as $key) {
            unset($values[$key]);
        }
        $context = $this->criteria($values, true);
        $builder = $this->query($context, true);
        $this->applyNameSearch($builder, $term, 't');
        $rows = $builder->orderBy('t.scientific_name')->orderBy('t.id')->get(20)->getResultArray();
        if ($rows === []) {
            return [];
        }
        $names = $this->db->table('taxon_names')->select('taxon_id, name')
            ->whereIn('taxon_id', array_column($rows, 'id'))->where('deleted_at', null)
            ->like('name', $term)->orderBy('name')->get()->getResultArray();
        $matched = [];
        foreach ($names as $name) {
            $matched[$name['taxon_id']] ??= $name['name'];
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'scientific_name' => $row['scientific_name'],
            'vernacular_name' => $row['vernacular_name'] ?? '', 'rank' => $row['rank_title'] ?? '',
            'matched_name' => $matched[$row['id']] ?? '',
        ], $rows);
    }

    /**
     * Load small reference lists and configured classification options.
     *
     * @return array<string, mixed> Available filter options.
     */
    private function options(): array
    {
        $options = ['ranks' => $this->ranks, 'classifications' => $this->classifications];
        foreach (['regions' => ['geographic_regions', 'higher_geography'],
            'groups' => ['taxon_groups', 'title'], 'dataSources' => ['data_sources', 'title']] as $key => [$table, $sort]) {
            $reference = $this->db->table($table);
            if ($table !== 'data_sources') {
                $reference->where('deleted_at', null);
            }
            $options[$key] = $reference->orderBy($sort)->get()->getResultArray();
        }
        $options['classificationOptions'] = [];
        if ($this->classifications !== []) {
            $rows = $this->db->table('taxa')->select('id, taxon_rank_id, scientific_name')
                ->whereIn('taxon_rank_id', array_column($this->classifications, 'id'))
                ->where('deleted_at', null)->orderBy('scientific_name')->get()->getResultArray();
            foreach ($this->classifications as $alias => $rank) {
                $options['classificationOptions'][$alias] = array_values(array_filter(
                    $rows, static fn (array $row): bool => (string) $row['taxon_rank_id'] === (string) $rank['id']
                ));
            }
        }

        return $options;
    }

    /**
     * Prepare display rows in batches without losing raw dates or region values.
     *
     * Load all region labels for the batch, not just the region used to filter records.
     * Keep missing/unequal date bounds visible instead of presenting an uncertain date
     * as exact. The raw bounds and region list remain available separately from labels.
     *
     * @param list<array<string, mixed>> $rows A result batch.
     * @param bool $taxa Whether rows are taxa.
     * @return list<array<string, mixed>> Rows with readable display fields.
     */
    public function hydrate(array $rows, bool $taxa): array
    {
        $regions = [];
        if (! $taxa && $rows !== []) {
            $links = $this->db->table('geographic_regions_occurrences gro')
                ->select('gro.occurrence_id, gr.higher_geography')
                ->join('geographic_regions gr', 'gr.id = gro.geographic_region_id AND gr.deleted_at IS NULL')
                ->whereIn('gro.occurrence_id', array_column($rows, 'id'))
                ->orderBy('gr.higher_geography')->get()->getResultArray();
            foreach ($links as $link) {
                $regions[$link['occurrence_id']][] = $link['higher_geography'];
            }
        }
        foreach ($rows as &$row) {
            $row['taxon_group_label'] = $row['taxon_group_friendly'] ?: ($row['taxon_group_title'] ?? '');
            if (! $taxa) {
                $row['regions'] = $regions[$row['id']] ?? [];
                $from = $row['from_date'] ?? '';
                $to = $row['to_date'] ?? '';
                $row['date_label'] = $from === $to ? ($from ?: 'Unknown')
                    : (($from ?: 'Unknown') . ' to ' . ($to ?: 'Unknown'));
            }
        }
        unset($row);

        return $rows;
    }

    /**
     * Apply date containment, region, source and occurrence moderation to one record alias.
     *
     * All predicates apply to the same record, preventing a region match from one occurrence
     * and a year match from another. Missing date bounds do not establish containment.
     * Compare DATE columns directly so existing date indexes remain usable.
     *
     * @param BaseBuilder $builder Query being constrained.
     * @param SearchCriteria $criteria Validated filters.
     * @param string $alias Internal occurrence alias.
     */
    private function applyOccurrenceFilters(BaseBuilder $builder, SearchCriteria $criteria, string $alias): void
    {
        foreach (['data_source_id', 'blocked'] as $key) {
            if ($criteria->value($key) !== '') {
                $builder->where($alias . '.' . $key, $criteria->value($key));
            }
        }
        if ($criteria->lowerDates !== [] || $criteria->upperDates !== []) {
            $builder->where($alias . '.from_date IS NOT NULL', null, false)
                ->where($alias . '.to_date IS NOT NULL', null, false)
                ->where("{$alias}.from_date <= {$alias}.to_date", null, false);
            foreach ($criteria->lowerDates as $date) {
                $builder->where($alias . '.from_date >=', $date);
            }
            foreach ($criteria->upperDates as $date) {
                $builder->where($alias . '.to_date <=', $date);
            }
        }
        if ($criteria->value('geographic_region_id') !== '') {
            // One selected region plus the link table's compound key yields at most one
            // joined row per occurrence; joining avoids dependent region subqueries.
            $builder->join('geographic_regions_occurrences matched_region', "matched_region.occurrence_id = {$alias}.id")
                ->join('geographic_regions matched_geography', 'matched_geography.id = matched_region.geographic_region_id AND matched_geography.deleted_at IS NULL')
                ->where('matched_region.geographic_region_id', $criteria->value('geographic_region_id'));
        }
    }

    /**
     * Match names without duplicating rows for multiple synonyms.
     *
     * EXISTS keeps name matches from multiplying rows or pagination totals. Compiling
     * without reset preserves the outer builder's aliases on the shared connection.
     *
     * @param BaseBuilder $builder Query being constrained.
     * @param string $term Search text.
     * @param string $alias Internal taxon alias.
     */
    private function applyNameSearch(BaseBuilder $builder, string $term, string $alias): void
    {
        $names = $this->db->table('taxon_names names')->select('1')
            ->where("names.taxon_id = {$alias}.id", null, false)
            ->where('names.deleted_at', null)->like('names.name', $term);
        $builder->groupStart()->like($alias . '.scientific_name', $term)
            ->orLike($alias . '.vernacular_name', $term)
            ->orWhere('EXISTS (' . $names->getCompiledSelect(false) . ')', null, false)->groupEnd();
    }

    /**
     * Match supported descriptive fields and linked names/regions.
     *
     * @param BaseBuilder $builder Query being constrained.
     * @param string $term Search text.
     * @param bool $taxa Whether this is a taxa query.
     */
    private function applyTextSearch(BaseBuilder $builder, string $term, bool $taxa): void
    {
        $builder->groupStart();
        $this->applyNameSearch($builder, $term, 't');
        $columns = ['t.id', 't.taxon_identifier', 't.scientific_name_identifier', 'tg.title', 'tg.friendly'];
        $columns = array_merge($columns, $taxa
            ? ['t.conservation_status', 't.rarity_group_name', 't.taxon_remarks']
            : ['o.id', 'o.unique_key', 'o.grid_ref', 'o.locality', 'o.recorded_by', 'o.identified_by',
                'recorded.name', 'ds.title', 'ds.abbr']);
        foreach ($this->classifications as $alias => $rank) {
            $columns[] = 'c_' . $alias . '.scientific_name';
        }
        foreach ($columns as $column) {
            $builder->orLike($column, $term);
        }
        if (! $taxa) {
            $regions = $this->db->table('geographic_regions_occurrences gro')->select('1')
                ->join('geographic_regions gr', 'gr.id = gro.geographic_region_id AND gr.deleted_at IS NULL')
                ->where('gro.occurrence_id = o.id', null, false)->like('gr.higher_geography', $term);
            $builder->orWhere('EXISTS (' . $regions->getCompiledSelect(false) . ')', null, false);
        }
        $builder->groupEnd();
    }

    /**
     * Include a selected taxon and descendants using projections or batched parent links.
     *
     * Reporting ranks have indexed ancestor/self projections. Other ranks use batched
     * breadth-first traversal of the accepted parent links, with visited IDs preventing
     * cycles. This works on MySQL 5.7 without requiring recursive CTE support.
     *
     * @param BaseBuilder $builder Query being constrained.
     * @param string $alias Internal taxon alias.
     * @param int $id Selected taxon ID.
     */
    private function applySubtree(BaseBuilder $builder, string $alias, int $id): void
    {
        // Nested get() calls reset connection-wide aliases; preserve those of the outer query.
        $aliases = $this->db->aliasedTables;
        $selected = $this->db->table('taxa')->select('taxon_rank_id')->where('id', $id)->get()->getRowArray();
        $this->db->setAliasedTables($aliases);
        foreach ($this->ranks as $rankAlias => $rank) {
            if ((string) $selected['taxon_rank_id'] === (string) $rank['id']) {
                $builder->groupStart()->where($alias . '.id', $id)
                    ->orWhere($alias . '.' . $rankAlias . '_id', $id)->groupEnd();

                return;
            }
        }
        $seen = [$id => true];
        $frontier = [$id];
        while ($frontier !== []) {
            $next = [];
            foreach (array_chunk($frontier, 500) as $batch) {
                $children = $this->db->table('taxa')->select('id')->whereIn('parent_taxon_id', $batch)
                    ->where('deleted_at', null)->get()->getResultArray();
                foreach ($children as $child) {
                    $childId = (int) $child['id'];
                    if (isset($seen[$childId])) {
                        continue;
                    }
                    $seen[$childId] = true;
                    $next[] = $childId;
                }
            }
            $frontier = $next;
        }
        $this->db->setAliasedTables($aliases);
        $builder->groupStart();
        foreach (array_chunk(array_keys($seen), 500) as $batch) {
            $builder->orWhereIn($alias . '.id', $batch);
        }
        $builder->groupEnd();
    }
}
