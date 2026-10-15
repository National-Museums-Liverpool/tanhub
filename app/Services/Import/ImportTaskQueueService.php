<?php

namespace App\Services\Import;

use App\Models\ImportOffsetModel;
use App\Models\ImportRunModel;
use App\Models\ImportTaskQueueModel;
use RuntimeException;
use Throwable;

/**
 * Shares admin request selection and lifecycle between web and scheduled imports.
 *
 * Selection, execution, recovery, retry and removal require the import lock.
 * Enqueuing remains available while another process holds that lock.
 */
class ImportTaskQueueService
{
    /**
     * Add a request unless it is already waiting, running or awaiting retry.
     *
     * @param string $sourceKey Registered task source key.
     * @return void
     */
    public function enqueue(string $sourceKey): void
    {
        $model = model(ImportTaskQueueModel::class);
        if ($model->where('source_key', $sourceKey)->whereIn('status', ['queued', 'running', 'failed'])->first() !== null) {
            return;
        }

        $inserted = $model->insert([
            'source_key' => $sourceKey,
            'status' => 'queued',
            'queued_at' => date('Y-m-d H:i:s'),
        ]);
        if ($inserted === false) {
            throw new RuntimeException('The import request could not be queued.');
        }
    }

    /**
     * List waiting, running and failed requests with their latest run details.
     *
     * @return array<int, array<string, mixed>> Requests in FIFO order.
     */
    public function rows(): array
    {
        $rows = model(ImportTaskQueueModel::class)
            ->whereIn('status', ['queued', 'running', 'failed'])
            ->orderBy('id', 'asc')->findAll();

        foreach ($rows as &$row) {
            $row['blocked_by'] = service('importTaskDependencyService')->blockedBy($row['source_key']);
            $run = empty($row['run_id']) ? null : model(ImportRunModel::class)->find($row['run_id']);
            $row['message'] = $run['message'] ?? null;
            if ($row['status'] === 'failed' && empty($row['message'])) {
                $row['message'] = 'The last attempt failed or was interrupted. Check its run history and logs before retrying.';
            }
        }

        return $rows;
    }

    /**
     * Select the oldest waiting request or an unfinished prerequisite.
     *
     * @return array<string, mixed>|null Selected task and owning request.
     */
    public function select(): ?array
    {
        $rows = model(ImportTaskQueueModel::class)
            ->where('status', 'queued')->orderBy('id', 'asc')->findAll();
        $failedKeys = $this->failedKeys();

        foreach ($rows as $row) {
            $sourceKey = $this->runnableTask((string) $row['source_key'], $failedKeys);
            if ($sourceKey !== null) {
                return [
                    'source_key' => $sourceKey,
                    'queue_id' => (int) $row['id'],
                    'requested_source_key' => (string) $row['source_key'],
                    'reason' => $sourceKey === $row['source_key']
                        ? 'oldest queued admin request'
                        : 'prerequisite for queued admin request ' . $row['source_key'],
                ];
            }
        }

        return null;
    }

    /**
     * Return failed requested tasks and the actual tasks that failed on their behalf.
     *
     * @return array<int, string> Tasks requiring explicit retry.
     */
    private function failedKeys(): array
    {
        $keys = [];
        foreach (model(ImportTaskQueueModel::class)->where('status', 'failed')->findAll() as $row) {
            $keys[] = $row['source_key'];
            $run = empty($row['run_id']) ? null : model(ImportRunModel::class)->find($row['run_id']);
            if ($run !== null) {
                $keys[] = $run['source_key'];
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Prevent routine automation from silently retrying failed admin requests.
     *
     * @param string $sourceKey Task automation wants to execute.
     * @return void
     *
     * @throws RuntimeException When a task or its prerequisite needs explicit retry.
     */
    public function assertRunnable(string $sourceKey): void
    {
        if (in_array($sourceKey, $this->failedKeys(), true)) {
            throw new RuntimeException('Import ' . $sourceKey . ' is awaiting Retry in the admin queue.');
        }
        foreach (service('importTaskDependencyService')->blockedBy($sourceKey) as $dependency) {
            $this->assertRunnable($dependency);
        }
    }

    /**
     * Find a runnable prerequisite without automatically retrying failed requests.
     *
     * @param string             $sourceKey Task to resolve.
     * @param array<int, string> $failedKeys Tasks awaiting explicit retry.
     * @return string|null Runnable task, or null when retry is required.
     */
    private function runnableTask(string $sourceKey, array $failedKeys): ?string
    {
        if (in_array($sourceKey, $failedKeys, true)) {
            return null;
        }

        $blockedBy = service('importTaskDependencyService')->blockedBy($sourceKey);
        if ($blockedBy === []) {
            return $sourceKey;
        }

        foreach ($blockedBy as $dependency) {
            $runnable = $this->runnableTask($dependency, $failedKeys);
            if ($runnable !== null) {
                return $runnable;
            }
        }

        return null;
    }

    /**
     * Execute one batch and retain its request until all requested work completes.
     *
     * @param array<string, mixed> $task Selected metadata.
     * @param callable(callable(int): void): array<string, mixed> $execute Batch executor.
     * @param bool $dryRun Whether queue state must remain unchanged.
     * @return array<string, mixed> Execution result.
     */
    public function run(array $task, callable $execute, bool $dryRun = false): array
    {
        if ($dryRun) {
            return $execute(static function (int $runId): void {});
        }

        $model = model(ImportTaskQueueModel::class);
        $row = $model->find($task['queue_id']);
        if ($row === null || $row['status'] !== 'queued') {
            throw new RuntimeException('The selected import request is no longer queued.');
        }

        $startedAt = date('Y-m-d H:i:s');
        $model->update($row['id'], [
            'status' => 'running',
            'started_at' => $startedAt,
            'finished_at' => null,
            'run_id' => null,
        ]);

        try {
            $result = $execute(static function (int $runId) use ($model, $row): void {
                $model->update($row['id'], ['run_id' => $runId]);
            });
            $runId = (int) ($result['run_id'] ?? 0);
            if ($runId > 0 && model(ImportRunModel::class)->find($runId) !== null) {
                $model->update($row['id'], ['run_id' => $runId]);
            }

            if (($result['status'] ?? null) !== 'success') {
                $model->update($row['id'], ['status' => 'failed', 'finished_at' => date('Y-m-d H:i:s')]);
                $run = $runId > 0 ? model(ImportRunModel::class)->find($runId) : null;
                if ($run !== null && empty($run['message'])) {
                    model(ImportRunModel::class)->update($runId, [
                        'message' => service('importTaskSummaryFormatter')->format($task['source_key'], $result),
                    ]);
                }
            } else {
                $hasMore = array_key_exists('has_more', $result)
                    ? $result['has_more'] === true
                    : ($task['kind'] === 'occurrence'
                        && ! model(ImportOffsetModel::class)->isComplete($task['source_key']));
                if ($task['source_key'] !== $row['source_key'] || $hasMore) {
                    $model->update($row['id'], ['status' => 'queued', 'finished_at' => null]);
                } else {
                    $model->where('source_key', $row['source_key'])->where('status', 'queued')->delete();
                    $model->delete($row['id']);
                }
            }

            return $result;
        } catch (Throwable $exception) {
            $runModel = model(ImportRunModel::class);
            $current = $model->find($row['id']);
            $run = empty($current['run_id']) ? null : $runModel->find($current['run_id']);
            $runId = $run['id'] ?? $runModel->insert([
                'source_key' => $task['source_key'],
                'source_abbr' => 'LOCAL',
                'status' => 'failed',
                'error_count' => 1,
                'message' => $exception->getMessage(),
                'started_at' => $startedAt,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            $model->update($row['id'], [
                'status' => 'failed',
                'run_id' => $runId,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
            log_message('error', 'Queued import {source} failed: {message}', [
                'source' => $task['source_key'],
                'message' => $exception->getMessage(),
            ]);
            throw $exception;
        }
    }

    /**
     * Recover abandoned executions only after acquiring the exclusive import lock.
     *
     * @return void
     */
    public function recover(): void
    {
        $model = model(ImportTaskQueueModel::class);
        $runModel = model(ImportRunModel::class);
        foreach ($model->where('status', 'running')->findAll() as $row) {
            $run = empty($row['run_id']) ? null : $runModel->find($row['run_id']);
            if ($run === null && ! empty($row['started_at'])) {
                $run = $runModel->where('source_key', $row['source_key'])
                    ->where('started_at >=', $row['started_at'])->orderBy('id', 'desc')->first();
            }

            if ($run !== null && $run['status'] === 'success') {
                if ($run['source_key'] === $row['source_key']
                    && model(ImportOffsetModel::class)->isComplete($row['source_key'])) {
                    $model->delete($row['id']);
                } else {
                    $model->update($row['id'], [
                        'status' => 'queued',
                        'run_id' => $run['id'],
                        'finished_at' => null,
                    ]);
                }
                continue;
            }

            if ($run !== null && $run['status'] === 'running') {
                $runModel->update($run['id'], [
                    'status' => 'failed',
                    'message' => 'Import was interrupted; its execution lock is no longer held. Retry to resume from the saved checkpoint.',
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
            }
            $model->update($row['id'], [
                'status' => 'failed',
                'run_id' => $run['id'] ?? null,
                'finished_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    /**
     * Retry a failed request from its saved checkpoint.
     *
     * @param int $id Queue row identifier.
     * @return void
     */
    public function retry(int $id): void
    {
        $model = model(ImportTaskQueueModel::class);
        $row = $model->find($id);
        if ($row === null || $row['status'] !== 'failed') {
            throw new RuntimeException('Only a failed import request can be retried.');
        }
        $model->update($id, ['status' => 'queued', 'started_at' => null, 'finished_at' => null]);
    }

    /**
     * Remove a waiting or failed request without resetting imported data.
     *
     * @param int $id Queue row identifier.
     * @return void
     */
    public function remove(int $id): void
    {
        $model = model(ImportTaskQueueModel::class);
        $row = $model->find($id);
        if ($row === null || $row['status'] === 'running') {
            throw new RuntimeException('Only a waiting or failed import request can be removed.');
        }
        $model->delete($id);
    }
}
