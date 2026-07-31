<?php

namespace Tests;

use App\Services\Import\ImportTaskSummaryFormatter;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class ImportTaskSummaryFormatterTest extends CIUnitTestCase
{
    /**
     * Format a successful task summary with counters.
     */
    public function testFormatsSuccessfulTaskSummary(): void
    {
        $formatter = new ImportTaskSummaryFormatter();

        $summary = $formatter->format('taxa', [
            'status' => 'success',
            'fetched' => 10,
            'inserted' => 8,
            'updated' => 2,
            'skipped' => 0,
            'errors' => 0,
        ]);

        $this->assertSame(
            'Task taxa finished with status success. Fetched: 10, inserted: 8, updated: 2, skipped: 0, errors: 0.',
            $summary,
        );
    }

    /**
     * Include the UI warning when records were skipped.
     */
    public function testAddsSkippedWarning(): void
    {
        $formatter = new ImportTaskSummaryFormatter();

        $summary = $formatter->format('occurrences', [
            'status' => 'success',
            'skipped' => 1,
            'errors' => 0,
        ]);

        $this->assertStringContainsString('Some records were skipped;', $summary);
    }

    /**
     * Include the UI error message when records failed.
     */
    public function testAddsFailureMessage(): void
    {
        $formatter = new ImportTaskSummaryFormatter();

        $summary = $formatter->format('taxon_stats', [
            'status' => 'failed',
            'errors' => 1,
        ]);

        $this->assertStringContainsString('Import stopped early because some records failed.', $summary);
    }
}
