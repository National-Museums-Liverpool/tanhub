<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Recomputes derived taxon rarity categories.
 */
class TaxonRarity extends BaseCommand
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
    protected $name = 'stats:taxon-rarity';

    /**
     * The command's description.
     *
     * @var string
     */
    protected $description = 'Recompute taxa.rarity_category from active occurrence counts and 2km grid square coverage.';

    /**
     * The command's usage description for the --help Spark option.
     *
     * @var string
     */
    protected $usage = 'stats:taxon-rarity [options]';

    /**
     * CLI options.
     *
     * @var array<string, string>
     */
    protected $options = [
        '--dry-run' => 'Calculate rarity categories without writing updates.',
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

        CLI::write('Starting taxon rarity recalculation' . ($dryRun ? ' (dry run)' : '') . '.', 'yellow');

        try {
            /** @var \App\Services\Import\DerivedImportRunner $runner */
            $runner = service('derivedImportRunner');
            $result = $runner->run('derived-stats:taxon_rarity', 'taxonRarityService', $dryRun);

            CLI::write('Task completed with status: ' . (string) ($result['status'] ?? 'unknown'), 'green');
            CLI::write(service('importTaskSummaryFormatter')->format('taxon_rarity', $result));
            CLI::write('Taxa analysed: ' . (int) ($result['analysed'] ?? 0) . ', Updated: ' . (int) ($result['updated'] ?? 0) . ', Not changed: ' . (int) ($result['notchanged'] ?? 0) . ', Errors: ' . (int) ($result['errors'] ?? 0));
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());
            $this->showError($exception);
        }
    }
}