<?php

namespace App\Services\Import;

/**
 * Formats import task results for operator-facing output.
 *
 * Turns the result array returned by an orchestrator/runner (e.g.
 * {@see \App\Services\Import\EntityImportOrchestrator::run()},
 * {@see \App\Services\Import\ImportOrchestrator::run()},
 * {@see \App\Services\Import\DerivedImportRunner::run()}) into a single
 * human-readable sentence used for the flash messages shown on the imports
 * dashboard (see {@see \App\Controllers\Imports::run()}).
 */
class ImportTaskSummaryFormatter
{
    /**
     * Format a completed task result using the admin UI wording.
     *
     * Builds a base sentence from the task label and status, then appends a
     * comma-separated list of any scalar count fields present in `$result`
     * that match a fixed allow-list of keys. Finally appends an extra
     * warning sentence when the result reports row errors (import stopped
     * early) or skipped rows (some records were intentionally not imported,
     * e.g. duplicates), so operators can distinguish "finished cleanly" from
     * "finished but needs attention" at a glance.
     *
     * @param string               $label  Human-readable task label.
     * @param array<string, mixed> $result Task result values; recognized keys are
     *                                     `status`, `fetched`, `processed`, `inserted`,
     *                                     `updated`, `not changed`, `skipped`, `errors`.
     *
     * @return string Formatted task summary.
     */
    public function format(string $label, array $result): string
    {
        $status = strtolower((string) ($result['status'] ?? 'success'));
        $summaryParts = [];
        $keysToInclude = ['fetched', 'processed', 'inserted', 'updated', 'not changed', 'skipped', 'errors'];

        foreach ($result as $key => $value) {
            if (is_scalar($value) && in_array($key, $keysToInclude, true)) {
                $summaryParts[] = $key . ': ' . (string) $value;
            }
        }

        $summary = sprintf('Task %s finished with status %s.', $label, $status);

        if ($summaryParts !== []) {
            $summary .= ' ' . ucfirst(implode(', ', $summaryParts)) . '.';
        }

        if (($result['errors'] ?? 0) > 0) {
            return $summary . ' Import stopped early because some records failed.';
        }

        if (($result['skipped'] ?? 0) > 0) {
            return $summary . ' Some records were skipped; review the import logs if this was unexpected.';
        }

        return $summary;
    }
}