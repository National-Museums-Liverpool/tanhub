<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Recomputes derived grid square stats counts.
 *
 * Thin CLI wrapper around {@see \App\Services\Import\DerivedImportRunner}
 * running the `derived-stats:grid_square_stats_counts` task via the
 * `gridSquareStatsCountsService`.
 */
class GridSquareStats extends BaseCommand
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
    protected $name = 'stats:grid-square-stats';

    /**
     * The command's description.
     *
     * @var string
     */
    protected $description = 'Recompute grid_square_stats occurrences_count, species_count and rarity_score from active occurrences.';

    /**
     * The command's usage description for the --help Spark option.
     *
     * @var string
     */
    protected $usage = 'stats:grid-square-stats [options]';

    /**
     * CLI options.
     *
     * @var array<string, string>
     */
    protected $options = [
        '--dry-run' => 'Calculate counts without writing updates.',
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

        CLI::write('Starting grid square stats counts recalculation' . ($dryRun ? ' (dry run)' : '') . '.', 'yellow');

        try {
            /** @var \App\Services\Import\DerivedImportRunner $runner */
            $runner = service('derivedImportRunner');
            $result = $runner->run('derived-stats:grid_square_stats_counts', 'gridSquareStatsCountsService', $dryRun);

            CLI::write('Task completed with status: ' . (string) ($result['status'] ?? 'unknown'), 'green');
            CLI::write(service('importTaskSummaryFormatter')->format('grid_square_stats_counts', $result));
            CLI::write('Fetched: ' . (int) ($result['fetched'] ?? 0) . ', Inserted: ' . (int) ($result['inserted'] ?? 0) . ', Updated: ' . (int) ($result['updated'] ?? 0) . ', Skipped: ' . (int) ($result['skipped'] ?? 0) . ', Errors: ' . (int) ($result['errors'] ?? 0));
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());
            $this->showError($exception);
        }
    }
}
