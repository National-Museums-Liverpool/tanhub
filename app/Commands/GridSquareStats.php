<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Recomputes derived grid square stats counts.
 */
class GridSquareStats extends BaseCommand
{
    /**
     * @var string
     */
    protected $group = 'TanHub';

    /**
     * @var string
     */
    protected $name = 'stats:grid-square-stats';

    /**
     * @var string
     */
    protected $description = 'Recompute grid_square_stats occurrences_count and species_count from active occurrences.';

    /**
     * @var string
     */
    protected $usage = 'stats:grid-square-stats [options]';

    /**
     * @var array<string, string>
     */
    protected $options = [
        '--dry-run' => 'Calculate counts without writing updates.',
    ];

    /**
     * Execute the command.
     */
    public function run(array $params)
    {
        $dryRun = (bool) CLI::getOption('dry-run') || array_key_exists('dry-run', $params);

        CLI::write('Starting grid square stats counts recalculation' . ($dryRun ? ' (dry run)' : '') . '.', 'yellow');

        try {
            /** @var \App\Services\Stats\GridSquareStatsCountsService $service */
            $service = service('gridSquareStatsCountsService');
            $result = $service->run($dryRun);

            CLI::write('Task completed with status: ' . (string) ($result['status'] ?? 'unknown'), 'green');
            CLI::write('Fetched: ' . (int) ($result['fetched'] ?? 0) . ', Inserted: ' . (int) ($result['inserted'] ?? 0) . ', Updated: ' . (int) ($result['updated'] ?? 0) . ', Skipped: ' . (int) ($result['skipped'] ?? 0) . ', Errors: ' . (int) ($result['errors'] ?? 0));
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());
            $this->showError($exception);
        }
    }
}
