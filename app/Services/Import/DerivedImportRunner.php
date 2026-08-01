<?php

namespace App\Services\Import;

use App\Models\ImportRunModel;
use RuntimeException;
use Throwable;

/**
 * Executes derived import tasks and records their outcomes.
 *
 * "Derived" tasks compute statistics/report data from already-imported
 * taxonomy and occurrence rows (e.g. taxon stats, grid square stats counts,
 * taxon rarity) rather than fetching from an external source adapter. This
 * runner wraps whichever CodeIgniter service implements the task (resolved by
 * name via `service($serviceName)`) with the same run-history bookkeeping
 * used by {@see \App\Services\Import\EntityImportOrchestrator} and
 * {@see \App\Services\Import\ImportOrchestrator}, so derived tasks show up
 * consistently in the imports dashboard.
 */
class DerivedImportRunner
{
    /**
     * Create a derived import runner.
     *
     * @param ImportRunModel|null $importRunModel Import run history model; resolved
     *                                            from the container when null.
     */
    public function __construct(private readonly ?ImportRunModel $importRunModel = null)
    {
    }

    /**
     * Execute a derived service and persist its run history.
     *
     * Inserts a `running` run row up front (so the task shows as in-progress
     * to any concurrent viewer), invokes `service($serviceName)->run($dryRun)`,
     * then updates the run row to `success` or `failed` based on the returned
     * `status` value. If the service throws, the run row is marked `failed`
     * with the exception message and the exception is rethrown to the caller
     * (e.g. {@see \App\Controllers\Imports::run()}) so it can surface the error.
     *
     * @param string $sourceKey   Task source key used for run-history tracking
     *                            (e.g. `derived-stats:taxon_stats`).
     * @param string $serviceName CodeIgniter service name implementing the task;
     *                            must expose a `run(bool $dryRun): array` method.
     * @param bool   $dryRun      Whether persistence is disabled for this run.
     *
     * @return array<string, mixed> The derived service's result array (shape
     *                              defined by that service; commonly includes
     *                              `status`, `fetched`/`processed`, `inserted`,
     *                              `updated`, `skipped`, `errors`), with `run_id`
     *                              added for the inserted run-history row.
     *
     * @throws RuntimeException When `sourceKey` or `serviceName` is blank.
     * @throws Throwable        Any exception thrown by the underlying service,
     *                          after the run row has been marked failed.
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