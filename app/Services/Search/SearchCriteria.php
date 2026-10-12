<?php

namespace App\Services\Search;

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
    /** @var array<string, string> Submitted scalar values. */
    public array $values = [];

    /** @var array<string, string> User-facing validation errors. */
    public array $errors = [];

    /** @var list<string> Inclusive YYYY-MM-DD lower bounds from year and precise-date filters. */
    public array $lowerDates = [];

    /** @var list<string> Inclusive YYYY-MM-DD upper bounds from year and precise-date filters. */
    public array $upperDates = [];

    /**
     * Parse supported filters without silently ignoring malformed input.
     *
     * @param array<string, mixed> $input Request query parameters.
     * @param list<string> $classificationKeys Available classification aliases.
     * @param bool $taxa Whether to allow the taxa-only rank filter.
     */
    public function __construct(array $input, array $classificationKeys, bool $taxa)
    {
        $keys = ['q', 'year', 'geographic_region_id', 'taxon_group_id', 'taxon_id',
            'taxon_name', 'data_source_id', 'blocked', 'taxon_blocked', 'from_date', 'to_date'];
        if ($taxa) {
            $keys[] = 'rank_id';
        }
        foreach ($classificationKeys as $alias) {
            $keys[] = $alias . '_id';
        }
        foreach ($input as $key => $value) {
            if (str_ends_with((string) $key, '_id') && ! in_array($key, $keys, true)) {
                $this->errors[$key] = 'This filter is not available on this page.';
            }
        }
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
        foreach (['year', 'from_date', 'to_date', 'geographic_region_id', 'data_source_id', 'blocked'] as $key) {
            if ($this->value($key) !== '') {
                return true;
            }
        }

        return false;
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
