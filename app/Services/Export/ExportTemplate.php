<?php

namespace App\Services\Export;

use InvalidArgumentException;
use RuntimeException;

/**
 * Define versioned CSV cells and order-preserving captured sort keys.
 */
final class ExportTemplate
{
    /** @var int Frozen template schema version. */
    public const VERSION = 2;

    /** @var list<string> Typed numeric fields that must not receive formula quoting. */
    private const NUMBERS = ['id', 'taxon_id', 'taxon_name_id', 'data_source_id', 'latitude',
        'longitude', 'occurrences_count', 'grid_square_count', 'frequency_trend'];

    /**
     * List templates available for a resource.
     *
     * @param bool $taxa Whether exporting taxa.
     * @return array<string, string> Template keys and labels.
     */
    public static function choices(bool $taxa): array
    {
        return $taxa ? ['grid' => 'Taxon grid', 'stats' => 'Taxon grid with stored all-date statistics']
            : ['grid' => 'Occurrence grid', 'details' => 'Detailed occurrences'];
    }

    /**
     * Resolve the exact ordered fields and headings for one template.
     *
     * @param bool $taxa Whether exporting taxa.
     * @param string $template Selected allowlisted template.
     * @param array<string, array<string, mixed>> $classifications Visible rank metadata.
     * @return array<string, string> Fields mapped to CSV headings.
     */
    public static function columns(bool $taxa, string $template, array $classifications): array
    {
        if (! isset(self::choices($taxa)[$template])) {
            throw new InvalidArgumentException('Choose a valid export template.');
        }
        $columns = $taxa ? [] : ['date_label' => 'Date'];
        $columns += ['scientific_name' => 'Scientific name', 'vernacular_name' => 'Vernacular name'];
        if ($taxa) {
            $columns['rank_title'] = 'Rank';
        }
        $columns['taxon_group_label'] = 'Taxon group';
        foreach ($classifications as $alias => $rank) {
            $columns[$alias . '_name'] = (string) $rank['rank'];
        }
        $columns += $taxa
            ? ['conservation_status' => 'Conservation status', 'taxon_identifier' => 'Taxon identifier']
            : ['regions' => 'Geographic regions', 'grid_ref' => 'Grid ref',
                'data_source_title' => 'Data source', 'unique_key' => 'Unique key'];
        $columns['blocked'] = 'Blocked';
        if ($template === 'details') {
            $columns += ['id' => 'Occurrence ID', 'taxon_id' => 'Exact taxon ID',
                'taxon_name_id' => 'Recorded name ID', 'recorded_name' => 'Recorded name',
                'from_date' => 'From date', 'to_date' => 'To date', 'recorded_by' => 'Recorder',
                'identified_by' => 'Identifier', 'identification_verification_status' => 'Verification status',
                'locality' => 'Locality', 'latitude' => 'Latitude', 'longitude' => 'Longitude',
                'grid_ref_2km' => '2 km grid ref', 'sex' => 'Sex', 'life_stage' => 'Life stage',
                'organism_quantity' => 'Quantity', 'data_source_id' => 'Data source ID',
                'blocked_reason' => 'Blocked reason'];
        }
        if ($template === 'stats') {
            $columns['stats_available'] = 'Stats available';
            foreach (['occurrences_count', 'grid_square_count', 'frequency_trend', 'frequency_trend_state',
                'first_record_date', 'last_record_date', 'first_recorder', 'last_recorder',
                'first_verified_record_date', 'last_verified_record_date',
                'first_verified_recorder', 'last_verified_recorder'] as $field) {
                $columns[$field] = ucfirst(str_replace('_', ' ', $field));
            }
        }

        return $columns;
    }

    /**
     * Format cells once at capture time, including spreadsheet formula protection.
     *
     * @param array<string, mixed> $row Hydrated captured row.
     * @param array<string, string> $columns Frozen columns.
     * @return list<string> Frozen CSV values.
     */
    public static function cells(array $row, array $columns): array
    {
        $cells = [];
        foreach ($columns as $field => $heading) {
            $value = $row[$field] ?? null;
            $text = match ($field) {
                'blocked', 'stats_available' => $value ? 'Yes' : 'No',
                'regions' => implode('; ', $value ?? []),
                default => $value === null ? '' : (string) $value,
            };
            if (! in_array($field, self::NUMBERS, true)
                && preg_match('/^[\s\x00-\x1f]*[=+@-]/u', $text) === 1) {
                $text = "'" . $text;
            }
            $cells[] = $text;
        }

        return $cells;
    }

    /**
     * Encode nullable sort values without losing numeric ordering or text collation.
     *
     * @param mixed $value Captured SQL value.
     * @param bool $numeric Whether the source column is an integer.
     * @return array{int, string} Presence flag and comparable value.
     */
    public static function sortKey(mixed $value, bool $numeric): array
    {
        if ($value === null) {
            return [0, ''];
        }
        $text = (string) $value;
        if ($numeric) {
            if (preg_match('/^-?\d{1,20}$/D', $text) !== 1) {
                throw new RuntimeException('Export integer sort value is invalid.');
            }
            $negative = str_starts_with($text, '-');
            $digits = str_pad(ltrim($text, '-'), 20, '0', STR_PAD_LEFT);
            $text = $negative ? '0' . strtr($digits, '0123456789', '9876543210') : '1' . $digits;
        }
        if (mb_strlen($text) > 255) {
            throw new RuntimeException('Export sort value exceeds the staged index capacity.');
        }

        return [1, $text];
    }
}
