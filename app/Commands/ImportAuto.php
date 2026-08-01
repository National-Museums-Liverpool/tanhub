<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Import as ImportConfig;
use Throwable;

/**
 * Selects and runs the next automated import task.
 *
 * Delegates selection and execution to `autoImportService`, which chooses
 * the next runnable task based on completion state and dependencies (see
 * {@see \App\Controllers\Imports::TASKS} and
 * {@see \App\Controllers\Imports::DEPENDENCIES} for the registry this
 * selection is based on).
 */
class ImportAuto extends BaseCommand
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
    protected $name = 'import:auto';

    /**
     * The command's description.
     *
     * @var string
     */
    protected $description = 'Select and run the next automated import task.';

    /**
     * The command's usage description for the --help Spark option.
     *
     * @var string
     */
    protected $usage = 'import:auto [options]';

    /**
     * CLI options.
     *
     * @var array<string, string>
     */
    protected $options = [
        '--limit' => 'Maximum records to fetch in this run.',
        '--page-size' => 'Page size per occurrence source request.',
        '--dry-run' => 'Fetch and validate without writing rows.',
    ];

    /**
     * Select and execute one import task.
     *
     * @param array<int|string, mixed> $params Command parameters.
     *
     * @return void
     */
    public function run(array $params)
    {
        $config = config(ImportConfig::class);
        $limit = (int) ($params['limit'] ?? CLI::getOption('limit') ?? $config->defaultLimit);
        $pageSize = (int) ($params['page-size'] ?? CLI::getOption('page-size') ?? $config->defaultPageSize);
        $dryRun = array_key_exists('dry-run', $params) || (bool) CLI::getOption('dry-run');

        try {
            $service = service('autoImportService');
            $task = $service->select();
            CLI::write('Selected task: ' . $task['source_key'], 'yellow');
            CLI::write('Reason: ' . $task['reason'], 'yellow');

            $execution = $service->run($limit, $pageSize, $dryRun, $task);
            $result = $execution['result'];
            CLI::write('Import completed with status: ' . (string) ($result['status'] ?? 'unknown'), 'green');

            if (isset($result['run_id'])) {
                CLI::write('Run ID: ' . (string) $result['run_id'], 'green');
            }

            CLI::write(service('importTaskSummaryFormatter')->format(
                (string) $task['source_key'],
                $result,
            ));
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());
            $this->showError($exception);
        }
    }
}