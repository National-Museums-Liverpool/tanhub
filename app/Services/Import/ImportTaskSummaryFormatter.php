<?php

namespace App\Services\Import;

/**
 * Formats import task results for operator-facing output.
 */
class ImportTaskSummaryFormatter
{
    /**
     * Format a completed task result using the admin UI wording.
     *
     * @param string               $label  Human-readable task label.
     * @param array<string, mixed> $result Task result values.
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