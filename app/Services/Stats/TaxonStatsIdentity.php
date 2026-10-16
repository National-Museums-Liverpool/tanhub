<?php

namespace App\Services\Stats;

/**
 * Builds stable identities for global and regional taxon-stat rows.
 */
final class TaxonStatsIdentity
{
    /**
     * Generate the stable UUID shared by derived and curated row creation.
     *
     * @param int $taxonId Taxon identifier.
     * @param int|null $regionId Geographic region identifier, or null for global.
     * @return string Stable UUID.
     */
    public static function uuidForScope(int $taxonId, ?int $regionId): string
    {
        $hex = md5($taxonId . '|' . ($regionId ?? 'global'));

        return sprintf(
            '%s-%s-4%s-%s%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 13, 3),
            dechex((hexdec(substr($hex, 16, 1)) & 0x3) | 0x8),
            substr($hex, 17, 3),
            substr($hex, 20, 12),
        );
    }
}
