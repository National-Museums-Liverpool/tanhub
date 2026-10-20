<?php

namespace App\Controllers;

use App\Models\ImportOffsetModel;
use App\Models\ImportTaskQueueModel;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Import as ImportConfig;
use RuntimeException;
use Throwable;

/**
 * Admin controller for reviewing and running import tasks.
 *
 * Displays task progress and explicit admin requests. Web and scheduled
 * execution share the same queue and run one bounded batch at a time.
 */
class Imports extends BaseController
{
    /**
     * Queue row statuses that still require an operator or runner action.
     *
     * @var array<int, string>
     */
    private const REQUEST_STATUSES = ['queued', 'running', 'failed'];

    /**
     * Registry of every import task that can be displayed and run from this page.
     *
     * Each entry is keyed by a unique source key (e.g. `indicia-taxonomy:taxa`)
     * and describes how the task is presented and executed:
     * - `category`     (string)      Group heading used on the imports page.
     * - `label`        (string)      Human-readable task name.
     * - `source`       (string|null) Data source passed to the orchestrator
     *                                (`indicia`, `nbn`), or null for derived tasks.
     * - `kind`         (string)      One of `entity`, `occurrence`, or `derived`;
     *                                determines which orchestrator/service runs the task
     *                                for the shared automatic import executor.
     * - `entity`       (string)      Entity name; only present for `entity` kind tasks.
     * - `service`      (string)      Service name; only present for `derived` kind tasks.
     * - `supports_run` (bool)        Whether the "Run" action is currently implemented.
     *
     * @var array<string, array<string, mixed>>
     */
    private const TASKS = [
        'indicia-taxonomy:recording_schemes' => [
            'category' => 'Lookups',
            'label' => 'recording_schemes',
            'source' => 'indicia',
            'kind' => 'entity',
            'entity' => 'recording_schemes',
            'supports_run' => true,
        ],
        'indicia-taxonomy:geographic_regions' => [
            'category' => 'Lookups',
            'label' => 'geographic_regions',
            'source' => 'indicia',
            'kind' => 'entity',
            'entity' => 'geographic_regions',
            'supports_run' => true,
        ],
        'indicia-taxonomy:grid_square_stats' => [
            'category' => 'Lookups',
            'label' => 'grid_square_stats',
            'source' => 'indicia',
            'kind' => 'entity',
            'entity' => 'grid_square_stats',
            'supports_run' => true,
        ],
        'indicia-taxonomy:taxon_groups' => [
            'category' => 'Taxonomy',
            'label' => 'taxon_groups',
            'source' => 'indicia',
            'kind' => 'entity',
            'entity' => 'taxon_groups',
            'supports_run' => true,
        ],
        'indicia-taxonomy:taxon_ranks' => [
            'category' => 'Taxonomy',
            'label' => 'taxon_ranks',
            'source' => 'indicia',
            'kind' => 'entity',
            'entity' => 'taxon_ranks',
            'supports_run' => true,
        ],
        'indicia-taxonomy:taxa' => [
            'category' => 'Taxonomy',
            'label' => 'taxa',
            'source' => 'indicia',
            'kind' => 'entity',
            'entity' => 'taxa',
            'supports_run' => true,
        ],
        'indicia-taxonomy:taxon_names' => [
            'category' => 'Taxonomy',
            'label' => 'taxon_names',
            'source' => 'indicia',
            'kind' => 'entity',
            'entity' => 'taxon_names',
            'supports_run' => true,
        ],
        'indicia-occurrences:occurrences' => [
            'category' => 'Occurrences',
            'label' => 'occurrences',
            'source' => 'indicia',
            'kind' => 'occurrence',
            'supports_run' => true,
        ],
        'nbn-occurrences:occurrences' => [
            'category' => 'Occurrences',
            'label' => 'occurrences',
            'source' => 'nbn',
            'kind' => 'occurrence',
            'supports_run' => true,
        ],
        'derived-stats:taxon_year_stats' => [
            'category' => 'Report stats',
            'label' => 'taxon_year_stats',
            'source' => null,
            'kind' => 'derived',
            'service' => 'taxonYearStatsService',
            'supports_run' => true,
        ],
        'derived-stats:taxon_stats' => [
            'category' => 'Report stats',
            'label' => 'taxon_stats',
            'source' => null,
            'kind' => 'derived',
            'service' => 'taxonStatsService',
            'supports_run' => true,
        ],
        'derived-stats:grid_square_stats_counts' => [
            'category' => 'Report stats',
            'label' => 'grid_square_stats_counts',
            'source' => null,
            'kind' => 'derived',
            'service' => 'gridSquareStatsCountsService',
            'supports_run' => true,
        ],
        'derived-stats:taxon_rarity' => [
            'category' => 'Report stats',
            'label' => 'taxon_rarity',
            'source' => null,
            'kind' => 'derived',
            'service' => 'taxonRarityService',
            'supports_run' => true,
        ],
    ];

    /**
     * Render the imports dashboard.
     *
     * Reading the dashboard never starts imports or changes execution state.
     *
     * @return string Rendered HTML for the imports page.
     */
    public function index(): string
    {
        $taskStates = $this->buildTaskStates();

        return $this->renderPage('imports/index', [
            'pageTitle' => 'Imports',
            'metaDescription' => 'Run and monitor import tasks.',
            'bodyClass' => 'app-shell',
            'taskStates' => $taskStates,
            'taskQueue' => $this->taskQueueRows(),
        ]);
    }

    /**
     * Queue a task, then attempt one batch without waiting for a busy import.
     *
     * @return RedirectResponse Redirect back to the imports page with
     *                          flashed status messages.
     */
    public function run(): RedirectResponse
    {
        $sourceKey = trim((string) $this->request->getPost('source_key'));

        if (! isset(self::TASKS[$sourceKey])) {
            return redirect()->to(site_url('imports'))->with('error', 'Unknown import task.');
        }

        service('importTaskQueueService')->enqueue($sourceKey);

        return $this->process();
    }

    /**
     * Process one existing queued batch without adding a request.
     *
     * @return RedirectResponse Redirect with execution or waiting details.
     */
    public function process(): RedirectResponse
    {
        $lock = service('importLock');
        if (! $lock->acquire()) {
            return redirect()->to(site_url('imports'))->with(
                'message',
                'Another import is running. Queued requests will be processed by the next scheduled import:auto invocation, or use Process next batch now once it finishes.',
            );
        }

        try {
            service('importTaskQueueService')->recover();
            $service = service('autoImportService');
            $task = $service->selectQueued();
            if ($task === null) {
                return redirect()->to(site_url('imports'))->with('warning', 'No runnable requests. Check the queue for failed requests that need Retry.');
            }
            $config = config(ImportConfig::class);
            $execution = $service->run($config->defaultLimit, $config->defaultPageSize, false, $task);
            $result = $execution['result'];
            $summary = service('importTaskSummaryFormatter')->format($task['source_key'], $result);
            $level = ($result['status'] ?? null) !== 'success'
                ? 'error'
                : (((int) ($result['skipped'] ?? 0)) > 0 ? 'warning' : 'message');

            $nextStep = $level === 'error'
                ? ' The failed request requires Retry in the current queue.'
                : ' Any remaining work stays queued for the next batch.';

            return redirect()->to(site_url('imports'))->with($level, $summary . $nextStep);
        } catch (Throwable $exception) {
            log_message('error', 'Admin import queue failed: {message}', ['message' => $exception->getMessage()]);

            return redirect()->to(site_url('imports'))->with('error', $exception->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * Retry or remove an existing request while holding the import lock.
     *
     * @return RedirectResponse Redirect with the outcome.
     */
    public function updateQueue(): RedirectResponse
    {
        $action = (string) $this->request->getPost('action');
        if (! in_array($action, ['retry', 'remove'], true)) {
            return redirect()->to(site_url('imports'))->with('error', 'Unknown queue action.');
        }
        $lock = service('importLock');
        if (! $lock->acquire()) {
            return redirect()->to(site_url('imports'))->with('warning', 'Another import is running. Try this queue action again once it finishes.');
        }
        try {
            $queue = service('importTaskQueueService');
            $queue->recover();
            $id = (int) $this->request->getPost('queue_id');
            if ($action === 'retry') {
                $queue->retry($id);
            } else {
                $queue->remove($id);
            }

            return redirect()->to(site_url('imports'))->with('message', $action === 'retry'
                ? 'Request queued for retry from its saved checkpoint.'
                : 'Request removed. Imported data and checkpoints were not changed.');
        } catch (RuntimeException $exception) {
            return redirect()->to(site_url('imports'))->with('error', $exception->getMessage());
        } finally {
            $lock->release();
        }
    }

    /**
     * Reset the selected task's progress so its next run starts at the beginning.
     *
     * @return RedirectResponse Redirect back to the imports page with a status message.
     */
    public function reset(): RedirectResponse
    {
        $sourceKey = trim((string) $this->request->getPost('source_key'));

        if (! isset(self::TASKS[$sourceKey])) {
            return redirect()->to(site_url('imports'))->with('error', 'Unknown import task.');
        }

        $queueModel = model(ImportTaskQueueModel::class);
        $lock = service('importLock');

        if (! $lock->acquire()) {
            return redirect()->to(site_url('imports'))->with(
                'message',
                'The import could not be restarted because another import is currently running.',
            );
        }

        try {
            if ($this->isTaskQueued($queueModel, $sourceKey)) {
                return redirect()->to(site_url('imports'))->with(
                    'error',
                    'Remove the existing queue request before restarting this task.',
                );
            }
            $this->resetTaskProgress($sourceKey, self::TASKS[$sourceKey]);
        } finally {
            $lock->release();
        }

        return redirect()->to(site_url('imports'))->with(
            'message',
            'Import task ' . self::TASKS[$sourceKey]['label'] . ' was restarted from the beginning.',
        );
    }

    /**
     * Reset persisted progress for one registered task and its geography children.
     *
     * All configured child streams must restart with the parent; otherwise
     * a grouped task would immediately reuse its old completed progress.
     *
     * @param string               $sourceKey Task source key.
     * @param array<string, mixed> $task      Registered task definition.
     *
     * @return void
     */
    private function resetTaskProgress(string $sourceKey, array $task): void
    {
        /** @var \App\Models\ImportOffsetModel $offsetModel */
        $offsetModel = model(\App\Models\ImportOffsetModel::class);
        $keys = [$sourceKey];
        if (in_array($sourceKey, [
            'indicia-taxonomy:geographic_regions',
            'indicia-taxonomy:grid_square_stats',
            'nbn-occurrences:occurrences',
        ], true)) {
            $groups = config(ImportConfig::class)->geographicRegions;
            if (count($groups) > 1) {
                foreach (array_keys($groups) as $group) {
                    $keys[] = $sourceKey . ':' . $group;
                }
            }
        }
        foreach ($keys as $key) {
            $offsetModel->setOffset($key, 0);
            $offsetModel->setCheckpoint($key, ($task['kind'] ?? null) === 'occurrence' ? '0' : null);
            $offsetModel->setCompletion($key, false);
        }
    }

    /**
     * Build the current display state for every task in the registry.
     *
     * For each task in {@see self::TASKS}, resolves its stored offset
     * (`is_complete`, `next_offset`, `next_checkpoint`), its current queue
     * status, and which of its dependencies (if any) are not yet complete.
     *
     * @return array<string, array<string, mixed>> Task state keyed by source key; each
     *                                              entry extends its {@see self::TASKS}
     *                                              definition with `is_complete`,
     *                                              `next_offset`, `next_checkpoint`,
     *                                              `queue_status`, and `blocked_by`.
     */
    private function buildTaskStates(): array
    {
        /** @var ImportOffsetModel $offsetModel */
        $offsetModel = model(ImportOffsetModel::class);
        $db = db_connect();
        $states = [];

        foreach (self::TASKS as $sourceKey => $task) {
            $offsetRow = $db->table('import_offsets')->where('source_key', $sourceKey)->get()->getRowArray();
            $isComplete = $offsetModel->isComplete($sourceKey);
            $nextOffset = $offsetRow['next_offset'] ?? null;
            $nextCheckpoint = $offsetRow['next_checkpoint'] ?? null;

            $states[$sourceKey] = [
                'category' => $task['category'],
                'label' => $task['label'],
                'source' => $task['source'],
                'kind' => $task['kind'],
                'entity' => $task['entity'] ?? null,
                'service' => $task['service'] ?? null,
                'source_key' => $sourceKey,
                'supports_run' => (bool) $task['supports_run'],
                'is_complete' => $isComplete,
                'next_offset' => is_scalar($nextOffset) ? (string) $nextOffset : null,
                'next_checkpoint' => is_scalar($nextCheckpoint) ? (string) $nextCheckpoint : null,
                'queue_status' => null,
                'blocked_by' => [],
            ];
        }

        foreach ($this->queueStatusBySourceKey() as $sourceKey => $queueStatus) {
            if (! isset($states[$sourceKey])) {
                continue;
            }

            $states[$sourceKey]['queue_status'] = $queueStatus;
        }

        $dependencyService = service('importTaskDependencyService');
        foreach ($states as $sourceKey => &$state) {
            $state['blocked_by'] = array_map(
                static fn (string $dependency): string => (string) ($states[$dependency]['label'] ?? $dependency),
                $dependencyService->blockedBy($sourceKey),
            );
        }
        unset($state);

        return $states;
    }

    /**
     * Return queued, running and failed requests in queue order.
     *
     * @return array<int, array<string, mixed>> Queue rows ordered oldest first (FIFO).
     */
    private function taskQueueRows(): array
    {
        return service('importTaskQueueService')->rows();
    }

    /**
     * Return outstanding request status keyed by source key.
     *
     * @return array<string, string> Queue status (`queued`, `running` or `failed`) indexed by
     *                               task source key.
     */
    private function queueStatusBySourceKey(): array
    {
        $statusBySourceKey = [];

        foreach ($this->taskQueueRows() as $row) {
            $sourceKey = (string) ($row['source_key'] ?? '');
            $status = (string) ($row['status'] ?? '');

            if ($sourceKey === '' || $status === '') {
                continue;
            }

            $statusBySourceKey[$sourceKey] = $status;
        }

        return $statusBySourceKey;
    }

    /**
     * Determine whether a task has an outstanding request.
     *
     * @param ImportTaskQueueModel $queueModel Queue model.
     * @param string               $sourceKey  Task source key to check.
     *
     * @return bool True if a queue row still needs runner or operator action.
     */
    private function isTaskQueued(ImportTaskQueueModel $queueModel, string $sourceKey): bool
    {
        return $queueModel
            ->where('source_key', $sourceKey)
            ->whereIn('status', self::REQUEST_STATUSES)
            ->countAllResults() > 0;
    }

}
