<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

/**
 * Imports records from configured external data sources.
 */
class ImportIndicia extends BaseCommand
{
    /**
     * @var string
     */
    protected $group = 'TanHub';

    /**
     * @var string
     */
    protected $name = 'import:indicia';

    /**
     * @var string
     */
    protected $description = 'Import records from Indicia into local lookup/taxa tables.';

    /**
     * @var string
     */
    protected $usage = 'import:indicia [options]';

    /**
     * @var array<string, string>
     */
    protected $options = [
        '--source' => 'Source key: indicia. Required.',
        '--entity' => 'Entity to import: recording_schemes, taxon_groups, taxon_ranks, geographic_regions, taxa.',
        '--limit' => 'Maximum records to fetch in this run.',
        '--offset' => 'Optional offset override. Defaults to stored offset for the source/entity.',
        '--dry-run' => 'Fetch and validate without writing rows.',
    ];

    /**
     * Execute the import command.
     */
    public function run(array $params)
    {
        $source = $params['source'] ?? CLI::getOption('source') ?? 'indicia';
        $entity = strtolower((string) ($params['entity'] ?? CLI::getOption('entity') ?? 'taxa'));

        $limit = (int) ($params['limit'] ?? CLI::getOption('limit') ?? 5000);
        $offsetOption = $params['offset'] ?? CLI::getOption('offset');
        log_message('info', 'Offset option: ' . ($offsetOption === null ? 'null' : (string) $offsetOption));
        $offset = $offsetOption !== null ? max(0, (int) $offsetOption) : null;
        log_message('info', 'Offset: ' . ($offset === null ? 'null' : (string) $offset));
        $dryRun = $this->resolveFlag($params, 'dry-run');

        $orchestrator = service('importOrchestrator');

        CLI::write('Starting import for source: ' . $source, 'yellow');
        CLI::write('Entity: ' . $entity . ' | Limit: ' . $limit . ($offset !== null ? ' | OFFSET: ' . $offset : '') . ($dryRun ? ' | DRY-RUN' : ''), 'yellow');

        try {
            $result = $orchestrator->run(
                $source,
                $entity,
                max(1, $limit),
                $dryRun,
                $offset,
            );

            CLI::write('Import completed with status: ' . $result['status'], 'green');
            CLI::write('Run ID: ' . $result['run_id'], 'green');
            CLI::write('Entity: ' . $result['entity'], 'green');
            CLI::write('Fetched: ' . $result['fetched'] . ', Inserted: ' . $result['inserted'] . ', Updated: ' . $result['updated'] . ', Skipped: ' . $result['skipped'] . ', Errors: ' . $result['errors']);
            CLI::write('Offset used: ' . (string) ($result['offset'] ?? 0) . ' | Next offset: ' . (string) ($result['next_offset'] ?? 0) . ' | Has more: ' . (($result['has_more'] ?? false) ? 'yes' : 'no'));
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());
            $this->showError($exception);
        }
    }

    /**
     * Resolve a boolean flag from CLI options or fallback param formats.
     *
     * @param array<string, mixed> $params
     */
    private function resolveFlag(array $params, string $name): bool
    {
        if ((bool) CLI::getOption($name)) {
            return true;
        }

        if (array_key_exists($name, $params)) {
            return true;
        }

        foreach ($params as $key => $value) {
            if (is_string($key) && ($key === '--' . $name || $key === '-' . $name)) {
                return true;
            }

            if (is_string($value) && ($value === '--' . $name || $value === '-' . $name)) {
                return true;
            }
        }

        if ($this->hasFlagInArgv($name)) {
            return true;
        }

        return false;
    }

    private function hasFlagInArgv(string $name): bool
    {
        $argv = $_SERVER['argv'] ?? [];

        if (! is_array($argv)) {
            return false;
        }

        foreach ($argv as $token) {
            if (! is_scalar($token)) {
                continue;
            }

            if ((string) $token === '--' . $name) {
                return true;
            }
        }

        return false;
    }
}