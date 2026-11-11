<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * Normalize national designations and resolve exact-taxon overrides without changing source data.
 */
final class TaxonDesignationService
{
    /** @var list<string> Imported designation fields that support local overrides. */
    public const FIELDS = ['gb_red_list', 'gb_status'];

    /**
     * Encode distinct abbreviations using the same lossless format for imports and staff edits.
     *
     * @param mixed $value Semicolon-separated abbreviations, or null to clear a designation.
     * @return string|null Bytewise-sorted list, or null when no abbreviations remain.
     * @throws InvalidArgumentException If input is not valid UTF-8 text or exceeds TEXT storage.
     */
    public static function normalize(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Taxon designation lists must be valid UTF-8 strings without NULs.');
        }
        $values = array_values(array_unique(array_filter(array_map('trim', explode(';', $value)),
            static fn (string $abbreviation): bool => $abbreviation !== '')));
        sort($values, SORT_STRING);
        $list = implode(';', $values);
        if (strlen($list) > 65535) {
            throw new InvalidArgumentException('Taxon designation list exceeds the 65535-byte TEXT storage limit.');
        }

        return $list === '' ? null : $list;
    }

    /**
     * Resolve a details-page value, retaining imported text unchanged when the override is blank.
     *
     * @param string|null $imported Original imported designation.
     * @param string|null $override Exact-taxon override, including manually populated values.
     * @return string|null Effective designation; a blank override cannot suppress imported data.
     */
    public static function resolve(?string $imported, ?string $override): ?string
    {
        return $override === null || trim($override) === '' ? $imported : $override;
    }

    /**
     * Build a trusted SQL expression for reading, filtering and ordering the taxon alias `t`.
     *
     * Strip ASCII whitespace only for the emptiness check, not from the returned value.
     * CHAR/REPLACE work on SQLite and MySQL, unlike driver-specific regular expressions.
     * NULs are rejected on write; the remaining characters match PHP's trim whitespace.
     *
     * @param string $field One of the application-owned designation field names.
     * @return string Parenthesized expression compatible with API field allow-lists.
     * @throws InvalidArgumentException If the field is not a supported designation.
     */
    public static function effectiveSql(string $field): string
    {
        if (! in_array($field, self::FIELDS, true)) {
            throw new InvalidArgumentException('Unsupported taxon designation field.');
        }
        $override = 't.' . $field . '_override';
        $blank = $override;
        foreach ([9, 10, 11, 13, 32] as $character) {
            $blank = "REPLACE({$blank}, CHAR({$character}), '')";
        }

        return "(CASE WHEN NULLIF({$blank}, '') IS NULL THEN t.{$field} ELSE {$override} END)";
    }
}
