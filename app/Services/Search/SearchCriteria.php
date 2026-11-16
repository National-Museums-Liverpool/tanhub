<?php

namespace App\Services\Search;

use App\Services\TaxonAttributeService;
use InvalidArgumentException;

/**
 * Validated, bookmarkable staff search criteria independent of pagination.
 *
 * Retains submitted values for form redisplay and collects errors instead of throwing
 * away malformed filters. {@see TaxonSearchService::criteria()} checks database references;
 * {@see TaxonSearchService::query()} suppresses results when errors remain.
 * Year and precise-date bounds accumulate so both constraints apply to one record interval.
 */
final class SearchCriteria
{
    /** @var array<string, mixed> Submitted scalar values and nested attribute predicates. */
    public array $values = [];

    /** @var array<string, array<string, mixed>> Validated exact-taxon attribute predicates. */
    public array $attributeCriteria = [];

    /** @var array<string, string> User-facing validation errors. */
    public array $errors = [];

    /** @var list<string> Inclusive YYYY-MM-DD lower bounds from year and precise-date filters. */
    public array $lowerDates = [];

    /** @var list<string> Inclusive YYYY-MM-DD upper bounds from year and precise-date filters. */
    public array $upperDates = [];

    /** @var array<int, array{field: string, operator: string, value: string|list<string>, upper: string, text: string}> Validated AND rows. */
    public array $conditions = [];

    /** @var array<int, self> Parsed legacy predicates keyed by condition position. */
    public array $legacyConditions = [];

    /**
     * Parse supported filters without silently ignoring malformed input.
     *
     * @param array<string, mixed> $input Request query parameters.
     * @param list<string> $classificationKeys Available classification aliases.
     * @param bool $taxa Whether filtering taxa rather than original occurrences.
     */
    public function __construct(array $input, array $classificationKeys, bool $taxa)
    {
        $fromForm = array_key_exists('filter_form', $input);
        if ($fromForm || array_key_exists('conditions', $input)) {
            $this->values['filter_form'] = '1';
        }
        // The last control detects PHP max_input_vars truncation before filters can widen.
        if ($fromForm && ($input['filter_complete'] ?? null) !== '1') {
            $this->errors['conditions'] = 'Too many submitted values. Remove some choices or conditions.';
        }
        $keys = ['q', 'year', 'geographic_region_id', 'taxon_group_id', 'recording_scheme_id', 'taxon_id',
            'taxon_name', 'data_source_id', 'blocked', 'taxon_blocked', 'from_date', 'to_date'];
        if ($taxa) {
            $keys[] = 'rank_id';
        }
        foreach ($classificationKeys as $alias) {
            $keys[] = $alias . '_id';
        }
        foreach ($input as $key => $value) {
            if (str_ends_with((string) $key, '_id') && ! str_starts_with((string) $key, 'attribute__')
                && ! in_array($key, $keys, true)) {
                $this->errors[$key] = 'This filter is not available on this page.';
            }
        }
        $this->parseConditions($input, $classificationKeys, $taxa);
        foreach ($keys as $key) {
            $value = $input[$key] ?? '';
            if (! is_scalar($value)) {
                $this->errors[$key] = 'Enter a single value.';
                $value = '';
            }
            $this->values[$key] = trim((string) $value);
            if (str_ends_with($key, '_id') && $this->values[$key] !== ''
                && (! ctype_digit($this->values[$key]) || (int) $this->values[$key] < 1)) {
                $this->errors[$key] = 'Choose a valid option.';
            }
        }
        foreach (['blocked', 'taxon_blocked'] as $key) {
            if (! in_array($this->values[$key], ['', '0', '1'], true)) {
                $this->errors[$key] = 'Choose All, No or Yes.';
            }
        }
        foreach (['q', 'taxon_name'] as $key) {
            if (mb_strlen($this->values[$key]) > 200) {
                $this->errors[$key] = 'Use no more than 200 characters.';
            }
        }
        $this->parseDates();
        $attributes = new TaxonAttributeService();
        foreach ($input as $key => $value) {
            if (! str_starts_with((string) $key, 'attribute__')) {
                continue;
            }
            if (is_array($value)) {
                $value = array_filter($value, static fn (mixed $part): bool => $part !== '' && $part !== []);
            }
            if ($value === '' || $value === []) {
                continue;
            }
            $this->values[$key] = $value;
            try {
                $this->attributeCriteria += $attributes->criteria([$key => $value]);
            } catch (InvalidArgumentException $exception) {
                $this->errors[$key] = $exception->getMessage();
            }
        }
        if (isset($this->values['filter_form'])) {
            $this->values['filter_complete'] = ! $fromForm || ($input['filter_complete'] ?? null) === '1' ? '1' : '0';
        }
    }

    /**
     * Parse the row contract separately from the legacy scalar bookmark contract.
     *
     * @param array<string, mixed> $input Submitted parameters.
     * @param list<string> $classificationKeys Installed classifications.
     * @param bool $taxa Whether displaying taxa.
     */
    private function parseConditions(array $input, array $classificationKeys, bool $taxa): void
    {
        $scope = $input['stats_scope'] ?? null;
        if (array_key_exists('stats_scope', $input)) {
            if (! is_string($scope) || ($scope !== 'global' && (! ctype_digit($scope) || (int) $scope < 1))) {
                $this->errors['stats_scope'] = 'Choose Global or an available region.';
                $scope = 'global';
            }
            $this->values['stats_scope'] = $scope;
        }
        $fields = SearchFieldCatalog::fields($classificationKeys, $taxa);
        $rows = array_key_exists('conditions', $input) ? $input['conditions'] : [];
        if (! is_array($rows) || ! array_is_list($rows) || count($rows) > 50) {
            $this->errors['conditions'] = 'Submit a list of no more than 50 conditions.';
            $rows = [];
        }
        if (array_key_exists('conditions', $input)) {
            $this->values['conditions'] = $rows;
        }
        foreach ($rows as $index => $row) {
            if ($row === ['field' => ''] || (is_array($row) && ($row['field'] ?? null) === ''
                && ($row['value'] ?? '') === '' && ($row['text'] ?? '') === '' && ($row['upper'] ?? '') === '')) {
                continue;
            }
            try {
                if (! is_array($row)) {
                    throw new InvalidArgumentException('Submit a field, operator and value.');
                }
                $condition = SearchFieldCatalog::condition($row, $fields);
                $field = $fields[$condition['field']];
                if (! empty($field['legacy'])) {
                    $legacyInput = [$condition['field'] => $condition['value']];
                    if ($field['type'] === 'taxon' && $condition['value'] === '') {
                        $legacyInput = ['taxon_name' => $condition['text']];
                    }
                    // Rank remains a flat taxa-only contract for old bookmarks/exports.
                    $legacy = new self($legacyInput, $classificationKeys, true);
                    if ($legacy->errors !== []) {
                        throw new InvalidArgumentException(implode(' ', $legacy->errors));
                    }
                    $this->legacyConditions[$index] = $legacy;
                }
                if ($field['domain'] === 'attribute') {
                    (new TaxonAttributeService())->criteria(self::attributeInput($condition));
                }
                $this->conditions[$index] = $condition;
            } catch (InvalidArgumentException $exception) {
                $this->errors['condition_' . $index] = $exception->getMessage();
            }
        }
    }

    /**
     * Read a scalar filter value.
     *
     * @param string $key Filter key.
     * @return string Submitted value, or empty when absent.
     */
    public function value(string $key): string
    {
        return $this->values[$key] ?? '';
    }

    /**
     * Determine whether taxa must have matching occurrence evidence.
     *
     * Taxonomic filters alone must still allow taxa with no records. In contrast,
     * even an occurrence-status-only filter requires a contributing record.
     *
     * @return bool Whether occurrence filters are active.
     */
    public function needsOccurrences(): bool
    {
        foreach ($this->legacyConditions as $condition) {
            if ($condition->needsOccurrences()) {
                return true;
            }
        }
        foreach (['year', 'from_date', 'to_date', 'geographic_region_id', 'data_source_id', 'blocked'] as $key) {
            if ($this->value($key) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Translate one attribute row into the existing exact-taxon attribute contract.
     *
     * @param array<string, mixed> $condition Validated builder row.
     * @return array<string, mixed> Attribute service input.
     */
    public static function attributeInput(array $condition): array
    {
        if ($condition['operator'] === 'empty') {
            return [$condition['field'] => ['eq' => 'null']];
        }
        $value = $condition['operator'] === 'between'
            ? ['gte' => $condition['value'], 'lte' => $condition['upper']]
            : [$condition['operator'] => $condition['value']];

        return [$condition['field'] => $value];
    }

    /**
     * Resolve explicit scope, retaining the historical export convention for old URLs.
     *
     * @return string Region ID, or empty for global statistics.
     */
    public function statisticsRegion(): string
    {
        $scope = $this->value('stats_scope');

        return $scope === '' ? $this->value('geographic_region_id') : ($scope === 'global' ? '' : $scope);
    }

    /**
     * Detect an explicit exclusion of blocked taxa for descendant occurrence evidence.
     *
     * @return bool Whether blocked exact children must not qualify a parent.
     */
    public function excludesBlockedTaxa(): bool
    {
        if ($this->value('taxon_blocked') === '0') {
            return true;
        }
        foreach ($this->legacyConditions as $condition) {
            if ($condition->value('taxon_blocked') === '0') {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep non-occurrence constraints when selecting bounded export candidates.
     *
     * @param array<string, array<string, mixed>> $fields Field catalogue for this search.
     * @return array<string, mixed> Criteria without contributing-record predicates.
     */
    public function withoutOccurrences(array $fields): array
    {
        $values = $this->values;
        $values['stats_scope'] = $this->statisticsRegion() ?: 'global';
        foreach (['year', 'from_date', 'to_date', 'geographic_region_id', 'data_source_id', 'blocked'] as $key) {
            unset($values[$key]);
        }
        if (isset($values['conditions'])) {
            $values['conditions'] = array_values(array_filter($this->conditions,
                static fn (array $row): bool => $fields[$row['field']]['domain'] !== 'occurrence'));
        }

        return $values;
    }

    /**
     * Parse inclusive year/date bounds and report invalid or conflicting intervals.
     *
     * A single year supplies both bounds; a missing range endpoint stays unbounded.
     * Round-trip date formatting rejects PHP's automatic normalization of invalid dates.
     */
    private function parseDates(): void
    {
        $year = $this->value('year');
        if ($year !== '') {
            if (preg_match('/^([0-9]{4})?(?:-([0-9]{4})?)?$/D', $year, $matches) !== 1
                || $year === '-' || str_contains($year, '0000')) {
                $this->errors['year'] = 'Use 2010, 2010-2015, 2010- or -2015.';
            } else {
                $from = $matches[1] ?? '';
                $to = str_contains($year, '-') ? ($matches[2] ?? '') : $from;
                if ($from !== '') {
                    $this->lowerDates[] = $from . '-01-01';
                }
                if ($to !== '') {
                    $this->upperDates[] = $to . '-12-31';
                }
            }
        }
        foreach (['from_date', 'to_date'] as $key) {
            $value = $this->value($key);
            if ($value === '') {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
            if ($date === false || $date->format('Y-m-d') !== $value || substr($value, 0, 4) === '0000') {
                $this->errors[$key] = 'Enter a valid date in YYYY-MM-DD format.';
            } elseif ($key === 'from_date') {
                $this->lowerDates[] = $value;
            } else {
                $this->upperDates[] = $value;
            }
        }
        if ($this->lowerDates !== [] && $this->upperDates !== []
            && max($this->lowerDates) > min($this->upperDates)) {
            $this->errors['year'] = 'The lower date/year must not exceed the upper date/year.';
        }
    }
}
