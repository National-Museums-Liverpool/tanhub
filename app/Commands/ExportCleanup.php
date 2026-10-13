<?php

namespace App\Commands;

use App\Services\Export\ExportService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Optionally reclaim one bounded batch of expired export files and staging rows.
 */
class ExportCleanup extends BaseCommand
{
    /** @var string Spark command group. */
    protected $group = 'tanhub';

    /** @var string Optional maintenance command. */
    protected $name = 'exports:cleanup';

    /** @var string Command summary. */
    protected $description = 'Reclaim a bounded batch of expired CSV exports and staging rows.';

    /**
     * Perform the same bounded cleanup used by the staff UI.
     *
     * @param array<int|string, mixed> $params Unused command parameters.
     */
    public function run(array $params): void
    {
        (new ExportService())->cleanup();
        CLI::write('Export cleanup batch completed.');
    }
}
