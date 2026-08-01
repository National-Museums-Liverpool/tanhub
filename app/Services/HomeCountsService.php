<?php

namespace App\Services;

/**
 * Provides cached homepage record counts (occurrences, taxa, geographic regions).
 *
 * Not part of the import pipeline itself; it reads the tables that the import
 * services (e.g. {@see \App\Services\Import\ImportOrchestrator},
 * {@see \App\Services\Import\EntityImportOrchestrator}) populate, and caches the
 * resulting counts for cheap homepage rendering.
 */
class HomeCountsService
{
    /**
     * Cache key under which the homepage table counts array is stored.
     */
    private const CACHE_KEY = 'home_panel_counts_v1';

    /**
     * How long cached homepage table counts remain valid, in seconds.
     */
    private const CACHE_TTL_SECONDS = 300;

    /**
     * Get active-record counts for homepage display.
     *
     * Returns the cached value when present and shaped correctly. Otherwise
     * recomputes the counts from the database and caches them for
     * {@see self::CACHE_TTL_SECONDS}. If any database error occurs while
     * recomputing, the error is logged and a zeroed result is returned
     * instead of throwing, so a database hiccup never breaks the homepage.
     *
     * @return array<string, int> Counts keyed by `occurrences`, `taxa`, and
     *                            `geographic_regions`.
     */
    public function getCounts(): array
    {
        $cache = cache();

        $cached = $cache->get(self::CACHE_KEY);

        if (is_array($cached) && isset($cached['occurrences'], $cached['taxa'], $cached['geographic_regions'])) {
            return [
                'occurrences' => (int) $cached['occurrences'],
                'taxa' => (int) $cached['taxa'],
                'geographic_regions' => (int) $cached['geographic_regions'],
            ];
        }

        try {
            $counts = [
                'occurrences' => $this->countActiveOccurrences(),
                'taxa' => $this->countActiveTaxa(),
                'geographic_regions' => $this->countActiveGeographicRegions(),
            ];

            $cache->save(self::CACHE_KEY, $counts, self::CACHE_TTL_SECONDS);
        } catch (\Throwable $exception) {
            log_message('warning', 'Home counts panel fallback applied: ' . $exception->getMessage());

            return [
                'occurrences' => 0,
                'taxa' => 0,
                'geographic_regions' => 0,
            ];
        }

        return $counts;
    }

    /**
     * Count active occurrences.
     *
     * Active occurrences are non-deleted and non-blocked.
     *
     * @return int
     */
    private function countActiveOccurrences(): int
    {
        return (int) db_connect()
            ->table('occurrences')
            ->where('deleted_at', null)
            ->where('blocked', 0)
            ->countAllResults();
    }

    /**
     * Count active taxa.
     *
     * Active taxa are non-deleted and non-blocked.
     *
     * @return int
     */
    private function countActiveTaxa(): int
    {
        return (int) db_connect()
            ->table('taxa')
            ->where('deleted_at', null)
            ->where('blocked', 0)
            ->countAllResults();
    }

    /**
     * Count active geographic regions.
     *
     * Active geographic regions are non-deleted rows.
     *
     * @return int
     */
    private function countActiveGeographicRegions(): int
    {
        return (int) db_connect()
            ->table('geographic_regions')
            ->where('deleted_at', null)
            ->countAllResults();
    }
}
