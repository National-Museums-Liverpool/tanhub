<?php

namespace App\Services\Import;

use App\Models\ImportRunModel;
use RuntimeException;
use Throwable;

/**
 * Executes derived import tasks and records their outcomes.
 */
class DerivedImportRunner
{
    /**
     * Create a derived import runner.
     *
     * @param ImportRunModel|null $importRunModel Import run history model.
     */
    public function __construct(private readonly ?ImportRunModel $importRunModel = null)
    {
    }

    /**
     * Execute a derived service and persist its run history.
     *
     * @param string $sourceKey Task source key.
    * @param string $serviceName CodeIgniter service name.
    * @param bool   $dryRun Whether persistence is disabled.
     *
     * @return array<string, mixed> Task result including the run ID.
     */
    public function run(string $sourceKey, string $serviceName, bool $dryRun = false): array
    {
        $sourceKey = trim($sourceKey);
        $serviceName = trim($serviceName);

        if ($sourceKey === '' || $serviceName === '') {
            throw new RuntimeException('Derived task configuration is incomplete.');
        }

        $importRunModel = $this->importRunModel ?? model(ImportRunModel::class);
        $runId = (int) $importRunModel->insert([
            'source_key' => $sourceKey,
            'source_abbr' => 'LOCAL',
            'status' => 'running',
            'checkpoint' => null,
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $result = service($serviceName)->run($dryRun);
            $status = strtolower((string) ($result['status'] ?? 'success')) === 'success'
                ? 'success'
                : 'failed';

            $importRunModel->update($runId, [
                'status' => $status,
                'fetched_count' => (int) ($result['fetched'] ?? $result['processed'] ?? 0),
                'inserted_count' => (int) ($result['inserted'] ?? 0),
                'updated_count' => (int) ($result['updated'] ?? 0),
                'skipped_count' => (int) ($result['skipped'] ?? 0),
                'error_count' => (int) ($result['errors'] ?? 0),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

            $result['run_id'] = $runId;

            return $result;
        } catch (Throwable $exception) {
            $importRunModel->update($runId, [
                'status' => 'failed',
                'error_count' => 1,
                'message' => $exception->getMessage(),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

            throw $exception;
        }
    }
}