<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Import as ImportConfig;
use Throwable;

/**
 * Imports occurrence records from configured external data sources.
 */
class ImportOccurrences extends BaseCommand
{
    /**
     * @var string
     */
    protected $group = 'TanHub';

    /**
     * @var string
     */
    protected $name = 'import:occurrences';

    /**
     * @var string
     */
    protected $description = 'Import occurrences from NBN Atlas or Indicia into local tables.';

    /**
     * @var string
     */
    protected $usage = 'import:occurrences [options]';

    /**
     * @var array<string, string>
     */
    protected $options = [
        '--source' => 'Source key: nbn or indicia. Required.',
        '--limit' => 'Maximum records to fetch in this run (default from config).',
        '--page-size' => 'Page size per source request (default from config).',
        '--since' => 'Optional checkpoint override.',
        '--dry-run' => 'Fetch and validate without writing occurrences.',
    ];

    /**
     * Execute the import command.
     */
    public function run(array $params)
    {
        $config = config(ImportConfig::class);

        $source = (string) ($params['source'] ?? CLI::getOption('source') ?? '');

        if ($source === '') {
            CLI::error('Missing required option: --source=nbn|indicia');
            return;
        }

        $limit = (int) ($params['limit'] ?? CLI::getOption('limit') ?? $config->defaultLimit);
        $pageSize = (int) ($params['page-size'] ?? CLI::getOption('page-size') ?? $config->defaultPageSize);
        $checkpoint = (string) ($params['since'] ?? CLI::getOption('since') ?? '');
        $dryRun = array_key_exists('dry-run', $params) || (bool) CLI::getOption('dry-run');

        $orchestrator = service('occurrenceImportOrchestrator');

        CLI::write('Starting import for source: ' . $source, 'yellow');
        CLI::write('Limit: ' . $limit . ' | Page size: ' . $pageSize . ($dryRun ? ' | DRY-RUN' : ''), 'yellow');

        try {
            $result = $orchestrator->run(
                $source,
                max(1, $limit),
                max(1, $pageSize),
                $dryRun,
                $checkpoint !== '' ? $checkpoint : null,
            );

            CLI::write('Import completed with status: ' . $result['status'], 'green');
            CLI::write('Run ID: ' . $result['run_id'], 'green');
            CLI::write('Fetched: ' . $result['fetched'] . ', Inserted: ' . $result['inserted'] . ', Updated: ' . $result['updated'] . ', Skipped: ' . $result['skipped'] . ', Errors: ' . $result['errors']);
            CLI::write('Checkpoint: ' . (string) ($result['checkpoint'] ?? '(none)'));
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());
            $this->showError($exception);
        }
    }
}
