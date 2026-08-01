<?php

namespace App\Controllers;

use App\Models\ImportOffsetModel;
use App\Models\ImportRunModel;
use App\Models\ImportTaskQueueModel;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Import as ImportConfig;
use RuntimeException;
use Throwable;

/**
 * Admin controller for reviewing and running import tasks.
 *
 * Provides the imports dashboard (task list with per-task offset/status and
 * the task queue) and the endpoint used to enqueue a task and drain the
 * queue in FIFO order until a task is blocked, fails, or throws. The full
 * task registry and dependency graph are declared as class constants; see
 * {@see self::TASKS} and {@see self::DEPENDENCIES}.
 */
class Imports extends BaseController
{
    /**
     * Queue row statuses that are considered "active" (not yet finished).
     *
     * @var array<int, string>
     */
    private const ACTIVE_QUEUE_STATUSES = ['queued', 'running'];

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
     *                                (see {@see self::runTask()}).
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
        'derived-stats:taxon_stats' => [
            'category' => 'Report stats',
            'label' => 'taxon_stats',
            'source' => null,
            'kind' => 'derived',
            'service' => 'taxonStatsService',
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
     * Dependency graph for the tasks declared in {@see self::TASKS}.
     *
     * Each entry maps a source key to the source keys that must be complete
     * (see {@see ImportOffsetModel::isComplete()}) before that task may run.
     * Consumed by {@see self::buildTaskStates()} to populate each task's
     * `blocked_by` list.
     *
     * @var array<string, array<int, string>>
     */
    private const DEPENDENCIES = [
        'indicia-taxonomy:grid_square_stats' => ['indicia-taxonomy:geographic_regions'],
        'indicia-taxonomy:taxa' => [
            'indicia-taxonomy:recording_schemes',
            'indicia-taxonomy:geographic_regions',
            'indicia-taxonomy:taxon_groups',
            'indicia-taxonomy:taxon_ranks',
        ],
        'indicia-taxonomy:taxon_names' => ['indicia-taxonomy:taxa'],
        'indicia-occurrences:occurrences' => [
            'indicia-taxonomy:recording_schemes',
            'indicia-taxonomy:geographic_regions',
            'indicia-taxonomy:grid_square_stats',
            'indicia-taxonomy:taxon_groups',
            'indicia-taxonomy:taxon_ranks',
            'indicia-taxonomy:taxa',
            'indicia-taxonomy:taxon_names',
        ],
        'nbn-occurrences:occurrences' => [
            'indicia-taxonomy:recording_schemes',
            'indicia-taxonomy:geographic_regions',
            'indicia-taxonomy:grid_square_stats',
            'indicia-taxonomy:taxon_groups',
            'indicia-taxonomy:taxon_ranks',
            'indicia-taxonomy:taxa',
            'indicia-taxonomy:taxon_names',
        ],
        'derived-stats:taxon_stats' => ['indicia-taxonomy:taxa'],
        'derived-stats:taxon_year_stats' => ['indicia-taxonomy:taxa'],
        'derived-stats:grid_square_stats_counts' => [
            'indicia-taxonomy:grid_square_stats',
        ],
        'derived-stats:taxon_rarity' => [
            'indicia-taxonomy:taxa',
        ],
    ];

    /**
     * Render the imports dashboard.
     *
     * Recovers any stale (abandoned) running tasks, then builds the full
     * task registry with each task's completion state and blocking
     * dependencies, alongside the current task queue.
     *
     * @return string Rendered HTML for the imports page.
     */
    public function index(): string
    {
        $this->recoverStaleTasks(model(ImportTaskQueueModel::class));
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
     * Queue the requested task, then drain the queue in FIFO order.
     *
     * Reads `source_key` from the POST body and adds it to the queue if it
     * is not already queued/running. If no task is currently running, the
     * queue is then processed one task at a time: each queued task is run
     * and removed from the queue, stopping as soon as a task is blocked by
     * an incomplete dependency, fails, or throws. Info/warning/error
     * messages accumulated while processing are flashed to the redirect.
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

        $queueModel = model(ImportTaskQueueModel::class);
        $this->recoverStaleTasks($queueModel);

        if (! $this->isTaskQueued($queueModel, $sourceKey)) {
            $queueModel->insert([
                'source_key' => $sourceKey,
                'status' => 'queued',
                'queued_at' => date('Y-m-d H:i:s'),
            ]);
        }

        if ($this->hasRunningTask($queueModel)) {
            return redirect()->to(site_url('imports'))->with('message', 'Task was queued. Another task is currently running.');
        }

        $infoMessages = [];
        $warningMessages = [];
        $errorMessages = [];

        while (true) {
            $nextQueued = $this->nextQueuedTask($queueModel);

            if ($nextQueued === null) {
                break;
            }

            $headSourceKey = (string) $nextQueued['source_key'];
            $taskStates = $this->buildTaskStates();
            $state = $taskStates[$headSourceKey] ?? null;

            if ($state === null) {
                $errorMessages[] = 'Unknown queued source key: ' . $headSourceKey . '.';
                $queueModel->delete((int) $nextQueued['id']);
                continue;
            }

            if ($state['blocked_by'] !== []) {
                $errorMessages[] = 'Queued task ' . $state['label'] . ' is blocked by: ' . implode(', ', $state['blocked_by']) . '.';
                $queueModel->delete((int) $nextQueued['id']);
                break;
            }

            $queueModel->update((int) $nextQueued['id'], [
                'status' => 'running',
                'started_at' => date('Y-m-d H:i:s'),
                'run_id' => null,
            ]);

            if (! $state['supports_run']) {
                $errorMessages[] = 'Task ' . $state['label'] . ' is not implemented yet.';
                $queueModel->delete((int) $nextQueued['id']);
                continue;
            }

            try {
                $result = $this->runTask($state);
                $runId = (int) ($result['run_id'] ?? 0);
                $summary = $this->summarizeTaskResult($state, $result);
                $runStatus = strtolower((string) ($result['status'] ?? 'success'));
                $queueStatus = $runStatus === 'success' ? 'completed' : 'failed';

                if ($runId > 0 && $this->importRunExists($runId)) {
                    $queueModel->update((int) $nextQueued['id'], ['run_id' => $runId]);
                }

                if ($runStatus !== 'success') {
                    $errorMessages[] = $summary;
                } elseif (((int) ($result['skipped'] ?? 0)) > 0) {
                    $warningMessages[] = $summary;
                } else {
                    $infoMessages[] = $summary;
                }

                $queueModel->delete((int) $nextQueued['id']);

                if ($queueStatus === 'failed') {
                    break;
                }
            } catch (Throwable $exception) {
                $errorMessages[] = 'Task ' . $state['label'] . ' failed: ' . $exception->getMessage();
                $queueModel->delete((int) $nextQueued['id']);
                break;
            }
        }

        if ($infoMessages === [] && $warningMessages === [] && $errorMessages === []) {
            return redirect()->to(site_url('imports'))->with('message', 'No tasks were processed.');
        }

        $redirect = redirect()->to(site_url('imports'));

        if ($infoMessages !== []) {
            $redirect = $redirect->with('message', implode(' ', $infoMessages));
        }

        if ($warningMessages !== []) {
            $redirect = $redirect->with('warning', implode(' ', $warningMessages));
        }

        if ($errorMessages !== []) {
            $redirect = $redirect->with('error', implode(' ', $errorMessages));
        }

        return $redirect;
    }

    /**
     * Format a user-facing summary for a completed task run.
     *
     * @param array<string, mixed> $state  Task state as built by {@see self::buildTaskStates()}.
     * @param array<string, mixed> $result Result returned by the orchestrator/service that ran the task.
     *
     * @return string Human-readable summary message.
     */
    private function summarizeTaskResult(array $state, array $result): string
    {
        return service('importTaskSummaryFormatter')->format(
            (string) ($state['label'] ?? 'unknown'),
            $result,
        );
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

        foreach (self::DEPENDENCIES as $sourceKey => $dependencies) {
            if (! isset($states[$sourceKey])) {
                continue;
            }

            $blockedBy = [];

            foreach ($dependencies as $dependencySourceKey) {
                $dependencyState = $states[$dependencySourceKey] ?? null;

                if ($dependencyState === null) {
                    continue;
                }

                if (! (bool) $dependencyState['is_complete']) {
                    $blockedBy[] = (string) $dependencyState['label'];
                }
            }

            $states[$sourceKey]['blocked_by'] = $blockedBy;
        }

        return $states;
    }

    /**
     * Execute a single task and return its raw orchestrator/service result.
     *
     * Dispatches to the entity import orchestrator, the occurrence import
     * orchestrator, or a derived stats service depending on the task's `kind`.
     *
     * @param array<string, mixed> $state Task state as built by {@see self::buildTaskStates()}.
     *
     * @return array<string, mixed> Result reported by the orchestrator/service; expected
     *                              to include at least a `status` key (and typically
     *                              `run_id` and `skipped`).
     *
     * @throws RuntimeException If the task's entity is missing, or its `kind` is not runnable.
     */
    private function runTask(array $state): array
    {
        $config = config(ImportConfig::class);
        $kind = (string) $state['kind'];

        if ($kind === 'entity') {
            $entity = (string) ($state['entity'] ?? '');
            $source = (string) ($state['source'] ?? 'indicia');

            if ($entity === '') {
                throw new RuntimeException('Task entity is missing.');
            }

            /** @var \App\Services\Import\EntityImportOrchestrator $orchestrator */
            $orchestrator = service('importOrchestrator');

            return $orchestrator->run(
                $source,
                $entity,
                max(1, (int) $config->defaultLimit),
                false,
                null,
            );
        }

        if ($kind === 'occurrence') {
            $source = (string) ($state['source'] ?? 'indicia');

            /** @var \App\Services\Import\ImportOrchestrator $orchestrator */
            $orchestrator = service('occurrenceImportOrchestrator');

            return $orchestrator->run(
                $source,
                max(1, (int) $config->defaultLimit),
                max(1, (int) $config->defaultPageSize),
                false,
                null,
            );
        }

        if ($kind === 'derived') {
            return $this->runDerivedTask($state);
        }

        throw new RuntimeException('Task is not runnable yet.');
    }

    /**
     * Return active queued/running rows in queue order.
     *
     * @return array<int, array<string, mixed>> Queue rows ordered oldest first (FIFO).
     */
    private function taskQueueRows(): array
    {
        /** @var ImportTaskQueueModel $queueModel */
        $queueModel = model(ImportTaskQueueModel::class);

        return $queueModel
            ->whereIn('status', self::ACTIVE_QUEUE_STATUSES)
            ->orderBy('id', 'asc')
            ->findAll();
    }

    /**
     * Return active queue status keyed by source key.
     *
     * @return array<string, string> Queue `status` (`queued` or `running`) indexed by
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
     * Determine whether a task is already queued or running.
     *
     * @param ImportTaskQueueModel $queueModel Queue model.
     * @param string               $sourceKey  Task source key to check.
     *
     * @return bool True if a queue row for this task already has an active status.
     */
    private function isTaskQueued(ImportTaskQueueModel $queueModel, string $sourceKey): bool
    {
        return $queueModel
            ->where('source_key', $sourceKey)
            ->whereIn('status', self::ACTIVE_QUEUE_STATUSES)
            ->countAllResults() > 0;
    }

    /**
     * Determine whether any queue item is currently running.
     *
     * @param ImportTaskQueueModel $queueModel Queue model.
     *
     * @return bool True if at least one queue row has status `running`.
     */
    private function hasRunningTask(ImportTaskQueueModel $queueModel): bool
    {
        return $queueModel->where('status', 'running')->countAllResults() > 0;
    }

    /**
     * Recover queue rows stuck in "running" past the configured stale timeout.
     *
     * A UI request can be interrupted (e.g. by a browser timeout) after a
     * task has started but before its queue row is updated. This finds any
     * such rows older than `Config\Import::$uiTaskStaleAfter`, marks their
     * linked import run as failed if it is still recorded as running, and
     * updates the queue row to `failed` (or `completed` if the run had in
     * fact succeeded) so the queue is not left permanently blocked.
     *
     * @param ImportTaskQueueModel $queueModel Queue model.
     *
     * @return void
     */
    private function recoverStaleTasks(ImportTaskQueueModel $queueModel): void
    {
        $staleAfter = max(1, (int) config(ImportConfig::class)->uiTaskStaleAfter);
        $staleBefore = date('Y-m-d H:i:s', time() - $staleAfter);
        $staleTasks = $queueModel
            ->where('status', 'running')
            ->where('started_at <', $staleBefore)
            ->findAll();

        if ($staleTasks === []) {
            return;
        }

        $importRunModel = model(ImportRunModel::class);
        $finishedAt = date('Y-m-d H:i:s');

        foreach ($staleTasks as $staleTask) {
            $runId = (int) ($staleTask['run_id'] ?? 0);
            $message = 'UI import task was recovered after exceeding the stale task timeout.';
            $queueStatus = 'failed';

            if ($runId > 0) {
                $run = $importRunModel->find($runId);

                if (is_array($run) && (string) ($run['status'] ?? '') === 'running') {
                    $importRunModel->update($runId, [
                        'status' => 'failed',
                        'message' => $message,
                        'finished_at' => $finishedAt,
                    ]);
                } elseif (is_array($run) && (string) ($run['status'] ?? '') === 'success') {
                    $queueStatus = 'completed';
                }
            }

            $queueModel->update((int) $staleTask['id'], [
                'status' => $queueStatus,
                'finished_at' => $finishedAt,
            ]);
        }
    }

    /**
     * Get the next queued item in FIFO order.
     *
     * @param ImportTaskQueueModel $queueModel Queue model.
     *
     * @return array<string, mixed>|null The oldest row with status `queued`, or null if
     *                                   the queue holds no queued rows.
     */
    private function nextQueuedTask(ImportTaskQueueModel $queueModel): ?array
    {
        $row = $queueModel
            ->where('status', 'queued')
            ->orderBy('id', 'asc')
            ->first();

        return is_array($row) ? $row : null;
    }

    /**
     * Check whether an import run row exists for the given ID.
     *
     * @param int $runId Import run ID; values <= 0 are treated as non-existent.
     *
     * @return bool True if a matching `import_runs` row exists.
     */
    private function importRunExists(int $runId): bool
    {
        if ($runId <= 0) {
            return false;
        }

        /** @var ImportRunModel $importRunModel */
        $importRunModel = model(ImportRunModel::class);

        return $importRunModel->where('id', $runId)->countAllResults() > 0;
    }

    /**
     * Execute a derived stats task and persist a matching import_runs row.
     *
     * Resolves the state's `service` name via {@see \App\Services\Import\DerivedImportRunner}.
     *
     * @param array<string, mixed> $state Task state as built by {@see self::buildTaskStates()};
     *                                    must include `source_key` and `service`.
     *
     * @return array<string, mixed> Result reported by the derived service, including a
     *                              `status` key.
     */
    private function runDerivedTask(array $state): array
    {
        $serviceName = trim((string) ($state['service'] ?? ''));
        $sourceKey = (string) ($state['source_key'] ?? '');

        /** @var \App\Services\Import\DerivedImportRunner $runner */
        $runner = service('derivedImportRunner');

        return $runner->run($sourceKey, $serviceName);
    }
}
