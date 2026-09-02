<?php

namespace App\Commands;

use App\Commands\Support\UsesImportLock;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\TaxonMediaImport;
use Throwable;

/**
 * Processes staged bulk taxon media imports in resumable batches.
 */
class ImportTaxonMedia extends BaseCommand
{
    use UsesImportLock;

    /** @var string */
    protected $group = 'tanhub';

    /** @var string */
    protected $name = 'taxon-media:work';

    /** @var string */
    protected $description = 'Process a staged bulk taxon media CSV import.';

    /** @var string */
    protected $usage = 'taxon-media:work [--import-id=123] [--limit=25]';

    /** @var array<string, string> */
    protected $options = [
        '--import-id' => 'Optional import ID; omit to process the oldest queued imports.',
        '--limit' => 'Maximum files to process in this worker batch.',
    ];

    /**
    * Process checkpointed batches until completion or the runtime limit.
     *
     * @param array<string, mixed> $params Command parameters.
     * @return void
     */
    public function run(array $params)
    {
        $importId = (int) ($params['import-id'] ?? CLI::getOption('import-id') ?? 0);
        $limitOption = $params['limit'] ?? CLI::getOption('limit');
        $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : null;

        try {
            $this->runWithImportLock(function () use ($importId, $limit): void {
                $startedAt = microtime(true);
                $result = null;

                do {
                    $result = $importId > 0
                        ? service('taxonMediaBulkImportService')->runBatch($importId, $limit)
                        : service('taxonMediaBulkImportService')->runNextBatch($limit);
                    if ($result === null) {
                        CLI::write('No queued taxon media imports.', 'green');
                        return;
                    }
                } while ($result['status'] === 'queued'
                    && microtime(true) - $startedAt < config(TaxonMediaImport::class)->workerMaxRuntimeSeconds);

                CLI::write('Import ' . $importId . ' status: ' . $result['status'], $result['status'] === 'failed' ? 'red' : 'green');
                CLI::write('Processed: ' . (int) $result['processed_rows'] . '/' . (int) $result['total_rows']);
                if (($result['error_message'] ?? null) !== null) {
                    CLI::error((string) $result['error_message']);
                }
            });
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());
            $this->showError($exception);
        }
    }
}
