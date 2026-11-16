<?php

namespace App\Services\Search;

use InvalidArgumentException;

/**
 * Allowlisted staff filter metadata shared by parsing, SQL and the typed row editor.
 *
 * Request field names never become SQL identifiers. Domains distinguish contributing
 * occurrence evidence from predicates on the displayed taxon and its stored statistics.
 */
final class SearchFieldCatalog
{
    /**
     * Describe fields available on one staff index.
     *
     * @param list<string> $classifications Installed classification aliases.
     * @param bool $taxa Whether displaying taxa instead of original occurrences.
     * @return array<string, array<string, mixed>> Metadata indexed by public field key.
     */
    public static function fields(array $classifications, bool $taxa): array
    {
        $fields = [];
        foreach ([
            'taxon_group_id' => ['Taxon group', 'reference'],
            'recording_scheme_id' => ['Recording scheme (including parents)', 'reference'],
            'rank_id' => ['Rank', 'reference'],
            'taxon_id' => ['Taxon (including descendants)', 'taxon'],
            'taxon_name' => ['Taxon names and synonyms', 'text'],
            'taxon_blocked' => ['Taxon blocked', 'flag'],
        ] as $key => [$label, $type]) {
            $fields[$key] = ['label' => $label, 'type' => $type, 'domain' => 'taxon', 'legacy' => true];
        }
        foreach ($classifications as $alias) {
            $fields[$alias . '_id'] = ['label' => ucfirst(str_replace('_', ' ', $alias)),
                'type' => 'reference', 'domain' => 'classification', 'legacy' => true];
        }
        foreach (['scientific_name', 'vernacular_name', 'taxon_identifier', 'scientific_name_identifier',
            'taxon_remarks', 'rarity_group_name', 'gb_red_list', 'gb_status', 'id_difficulty',
            'rarity_category', 'exclude_from_rarity_calculations'] as $key) {
            $type = match ($key) {
                'gb_red_list', 'gb_status' => 'tokens',
                'id_difficulty', 'rarity_category' => 'integer',
                'rarity_group_name' => 'choice',
                'exclude_from_rarity_calculations' => 'flag',
                default => 'text',
            };
            $fields[$key] = ['label' => ucfirst(str_replace('_', ' ', $key)), 'type' => $type,
                'domain' => 'taxon', 'column' => $key];
            if ($type === 'integer') {
                $fields[$key] += ['min' => 1, 'max' => 5];
            }
        }
        $fields['gb_red_list']['label'] = 'GB Red List';
        $fields['gb_status']['label'] = 'GB Status';
        $fields['id_difficulty']['label'] = 'Identification difficulty';
        $fields['scientific_name']['help'] = 'Enter all or part of a scientific name, then select Search. '
            . 'For a taxon picker that includes descendants, choose Taxon (including descendants).';
        $fields['taxon_id']['help'] = 'Type at least two characters and choose a taxon to include it and its descendants. '
            . 'To match scientific name text instead, choose Scientific name.';
        $fields['taxon_name']['help'] = 'Matches scientific names, vernacular names and synonyms as text. '
            . 'Enter a name, then select Search.';
        foreach (config(\Config\TaxonAttributes::class)->definitions() as $key => $definition) {
            $fields['attribute__' . $key] = ['label' => $definition['label'],
                'type' => $definition['type'] === 'term' ? 'choice' : 'integer', 'domain' => 'attribute'];
        }
        foreach (['year' => ['Year (record interval)', 'year'],
            'from_date' => ['From date (record interval)', 'date'],
            'to_date' => ['To date (record interval)', 'date'],
            'geographic_region_id' => ['Occurrence geographic region', 'reference'],
            'data_source_id' => ['Data source', 'reference'],
            'blocked' => ['Occurrence blocked', 'flag']] as $key => [$label, $type]) {
            $fields[$key] = ['label' => $label, 'type' => $type, 'domain' => 'occurrence', 'legacy' => true];
        }
        if (! $taxa) {
            foreach (['unique_key', 'recorded_name', 'grid_ref', 'grid_ref_2km', 'locality', 'recorded_by',
                'identified_by', 'identification_verification_status', 'sex', 'life_stage',
                'organism_quantity'] as $key) {
                $fields[$key] = ['label' => ucfirst(str_replace('_', ' ', $key)), 'type' => 'text',
                    'domain' => 'occurrence', 'column' => $key];
                if (in_array($key, ['sex', 'life_stage', 'identification_verification_status'], true)) {
                    $fields[$key]['type'] = 'textlist';
                }
            }
        }
        foreach (['available' => 'flag', 'occurrences_count' => 'integer', 'grid_square_count' => 'integer',
            'mean_occurrences_per_year' => 'decimal', 'mean_squares_per_year' => 'decimal',
            'occupied_square_score' => 'decimal', 'occupied_square_percentage' => 'decimal',
            'frequency_trend' => 'integer', 'frequency_trend_state' => 'choice',
            'key_species' => 'choice', 'rarity_status' => 'choice', 'first_record_date' => 'date',
            'last_record_date' => 'date', 'first_verified_record_date' => 'date',
            'last_verified_record_date' => 'date'] as $key => $type) {
            $fields['stats__' . $key] = ['label' => 'Statistics: ' . str_replace('_', ' ', $key),
                'type' => $type, 'domain' => 'statistics', 'column' => $key];
            if (in_array($type, ['integer', 'decimal'], true)) {
                $fields['stats__' . $key]['min'] = 0;
                $fields['stats__' . $key]['max'] = in_array($key,
                    ['frequency_trend', 'occupied_square_score', 'occupied_square_percentage'], true)
                    ? 100 : 2147483647;
            }
        }

        return $fields;
    }

    /**
     * Return meaningful operators and captions for a field.
     *
     * @param array<string, mixed> $field Trusted field metadata.
     * @return array<string, string> Operators in default presentation order.
     */
    public static function operators(array $field): array
    {
        if (($field['column'] ?? '') === 'recorded_name') {
            return ['eq' => 'Is', 'contains' => 'Contains'];
        }
        if ($field['domain'] === 'attribute' && $field['type'] === 'integer') {
            return ['eq' => 'Is', 'gte' => 'At least', 'lte' => 'At most',
                'between' => 'Between (inclusive)', 'empty' => 'Not set'];
        }
        if ($field['domain'] === 'attribute') {
            return ['in' => 'Includes any', 'empty' => 'Not set'];
        }
        if (! empty($field['legacy'])) {
            return match ($field['type']) {
                'taxon' => ['eq' => 'Including descendants'],
                'text' => ['contains' => 'Contains'],
                'date' => [$field['label'] === 'From date (record interval)' ? 'gte' : 'lte'
                    => $field['label'] === 'From date (record interval)' ? 'On or after' : 'On or before'],
                default => ['eq' => 'Is'],
            };
        }
        $operators = match ($field['type']) {
            'integer', 'decimal', 'date' => ['eq' => 'Is', 'gte' => 'At least / on or after',
                'lte' => 'At most / on or before', 'gt' => 'Greater than / after',
                'lt' => 'Less than / before', 'between' => 'Between (inclusive)'],
            'choice', 'tokens' => ['in' => 'Includes any'],
            'textlist' => ['eq' => 'Is', 'in' => 'Is any (comma-separated)'],
            'flag', 'reference' => ['eq' => 'Is'],
            default => ['contains' => 'Contains', 'eq' => 'Is', 'prefix' => 'Starts with'],
        };
        if ($field['type'] !== 'flag' && $field['domain'] !== 'attribute') {
            $operators += ['empty' => 'Not set / empty', 'not_empty' => 'Is set / not empty'];
        }

        return $operators;
    }

    /**
     * Validate one typed row without using driver coercion or accepting nested payloads.
     *
     * @param array<string, mixed> $row Submitted field/operator/value/upper/text row.
     * @param array<string, array<string, mixed>> $fields Available field metadata.
     * @return array<string, mixed> Normalized row suitable for URLs and export contracts.
     * @throws InvalidArgumentException If a row has an unsupported field or invalid value.
     */
    public static function condition(array $row, array $fields): array
    {
        $fieldKey = $row['field'] ?? '';
        $operator = $row['operator'] ?? '';
        if (! is_string($fieldKey) || ! isset($fields[$fieldKey])) {
            throw new InvalidArgumentException('Choose an available field.');
        }
        $field = $fields[$fieldKey];
        if (! is_string($operator) || ! isset(self::operators($field)[$operator])) {
            throw new InvalidArgumentException('Choose an available operator.');
        }
        $result = ['field' => $fieldKey, 'operator' => $operator, 'value' => '', 'upper' => '', 'text' => ''];
        if (in_array($operator, ['empty', 'not_empty'], true)) {
            return $result;
        }
        foreach (['value', 'upper', 'text'] as $key) {
            $value = $row[$key] ?? '';
            if ($key === 'value' && $operator === 'in') {
                if ($field['type'] === 'textlist' && is_string($value)) {
                    $value = explode(',', $value);
                }
                $value = is_array($value) ? $value : [$value];
                if (! array_is_list($value) || count($value) > 200) {
                    throw new InvalidArgumentException('Choose a list of values.');
                }
                $result[$key] = array_values(array_unique(array_map(
                    static fn (mixed $part): string => self::scalar($part), $value
                )));
                if ($result[$key] === [] || in_array('', $result[$key], true)) {
                    throw new InvalidArgumentException('Choose at least one value.');
                }
                if ($field['type'] === 'tokens' && str_contains(implode('', $result[$key]), ';')) {
                    throw new InvalidArgumentException('Choose individual designation abbreviations.');
                }
            } else {
                $result[$key] = self::scalar($value);
            }
        }
        if ($field['type'] === 'taxon' && $result['value'] === '' && $result['text'] !== '') {
            return $result;
        }
        foreach ($operator === 'between' ? ['value', 'upper'] : ['value'] as $key) {
            $value = $result[$key];
            if (is_array($value)) {
                continue;
            }
            if ($value === '') {
                throw new InvalidArgumentException('Enter a value.');
            }
            if (in_array($field['type'], ['integer', 'decimal'], true)) {
                $pattern = $field['type'] === 'integer' ? '/^-?\d+$/D' : '/^-?\d+(?:\.\d{1,4})?$/D';
                if (preg_match($pattern, $value) !== 1 || ! is_finite((float) $value)
                    || (float) $value < ($field['min'] ?? PHP_INT_MIN)
                    || (float) $value > ($field['max'] ?? PHP_INT_MAX)) {
                    throw new InvalidArgumentException('Enter a number within the field range (up to 4 decimal places).');
                }
            } elseif ($field['type'] === 'date') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if ($date === false || $date->format('Y-m-d') !== $value || str_starts_with($value, '0000')) {
                    throw new InvalidArgumentException('Enter a valid date in YYYY-MM-DD format.');
                }
            } elseif (in_array($field['type'], ['reference', 'taxon'], true)) {
                if (! ctype_digit($value) || (int) $value < 1) {
                    throw new InvalidArgumentException('Choose a valid option.');
                }
            } elseif ($field['type'] === 'flag' && ! in_array($value, ['0', '1'], true)) {
                throw new InvalidArgumentException('Choose Yes or No.');
            }
        }
        if ($operator === 'between') {
            $reversed = $field['type'] === 'date' ? $result['value'] > $result['upper']
                : (float) $result['value'] > (float) $result['upper'];
            if ($reversed) {
                throw new InvalidArgumentException('The minimum must not exceed the maximum.');
            }
        }
        if ($fieldKey === 'stats__key_species'
            && array_diff($result['value'], ['0', '1']) !== []) {
            throw new InvalidArgumentException('Choose Yes, No or Not set.');
        }
        if ($fieldKey === 'stats__rarity_status'
            && array_diff($result['value'], ['common', 'rare']) !== []) {
            throw new InvalidArgumentException('Choose Common, Rare or Not set.');
        }
        if ($fieldKey === 'stats__frequency_trend_state'
            && array_diff($result['value'], ['increasing', 'decreasing', 'stable', 'insufficient_data', 'unclear']) !== []) {
            throw new InvalidArgumentException('Choose an available trend state.');
        }

        return $result;
    }

    /**
     * Normalize a bounded, safe form string while explicitly rejecting malformed values.
     *
     * @param mixed $value Submitted scalar.
     * @return string Trimmed UTF-8 text.
     * @throws InvalidArgumentException If text is nested, invalid or excessively long.
     */
    private static function scalar(mixed $value): string
    {
        if (! is_scalar($value) || ! mb_check_encoding((string) $value, 'UTF-8')
            || str_contains((string) $value, "\0") || mb_strlen((string) $value) > 200) {
            throw new InvalidArgumentException('Enter a single value of no more than 200 characters.');
        }

        return trim((string) $value);
    }
}
