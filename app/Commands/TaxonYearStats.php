<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Recomputes derived taxon year stats.
 */
class TaxonYearStats extends BaseCommand
{
    /**
     * The group the command is lumped under when using Spark list.
     *
     * @var string
     */
    protected $group = 'tanhub';

    /**
     * The command's name.
     *
     * @var string
     */
    protected $name = 'stats:taxon-year-stats';

    /**
     * The command's description.
     *
     * @var string
     */
    protected $description = 'Recompute taxon_year_stats from active occurrences for the latest ten years.';

    /**
     * The command's usage description for the --help Spark option.
     *
     * @var string
     */
    protected $usage = 'stats:taxon-year-stats [options]';

    /**
     * CLI options.
     *
     * @var array<string, string>
     */
    protected $options = [
        '--dry-run' => 'Calculate taxon year stats without writing updates.',
    ];

    /**
     * Execute the command.
     *
     * @param array<int|string, mixed> $params Command parameters.
     *
     * @return void
     */
    public function run(array $params)
    {
        $dryRun = (bool) CLI::getOption('dry-run') || array_key_exists('dry-run', $params);

        CLI::write('Starting taxon year stats recalculation' . ($dryRun ? ' (dry run)' : '') . '.', 'yellow');

        try {
            /** @var \App\Services\Import\DerivedImportRunner $runner */
            $runner = service('derivedImportRunner');
            $result = $runner->run('derived-stats:taxon_year_stats', 'taxonYearStatsService', $dryRun);

            CLI::write('Task completed with status: ' . (string) ($result['status'] ?? 'unknown'), 'green');
            CLI::write(service('importTaskSummaryFormatter')->format('taxon_year_stats', $result));
            CLI::write('Fetched: ' . (int) ($result['fetched'] ?? 0) . ', Processed: ' . (int) ($result['processed'] ?? 0) . ', Inserted: ' . (int) ($result['inserted'] ?? 0) . ', Updated: ' . (int) ($result['updated'] ?? 0) . ', Not changed: ' . (int) ($result['not changed'] ?? 0) . ', Skipped: ' . (int) ($result['skipped'] ?? 0) . ', Errors: ' . (int) ($result['errors'] ?? 0));
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());
            $this->showError($exception);
        }
    }
}
