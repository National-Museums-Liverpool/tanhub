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
 * Admin page for import task status and execution.
 */
class Imports extends BaseController
{
    /**
     * @var array<int, string>
     */
    private const ACTIVE_QUEUE_STATUSES = ['queued', 'running'];

    /**
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
     * Show import task list, statuses, and queue.
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
     * Queue a task and process the queue in-order until blocked.
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
     * @param array<string, mixed> $state
     * @param array<string, mixed> $result
     * @return string
     */
    private function summarizeTaskResult(array $state, array $result): string
    {
        return service('importTaskSummaryFormatter')->format(
            (string) ($state['label'] ?? 'unknown'),
            $result,
        );
    }

    /**
     * @return array<string, array<string, mixed>>
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
     * @param array<string, mixed> $state
     * @return array<string, mixed>
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
     * @return array<int, array<string, mixed>>
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
     * @return array<string, string>
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
     * @param string               $sourceKey Source key.
     *
     * @return bool
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
     * @return bool
     */
    private function hasRunningTask(ImportTaskQueueModel $queueModel): bool
    {
        return $queueModel->where('status', 'running')->countAllResults() > 0;
    }

    /**
     * Mark UI tasks abandoned by a timed-out request as failed so the queue can resume.
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
     * @return array<string, mixed>|null
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
     * Check whether an import run row exists.
     *
     * @param int $runId
     * @return bool
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
     * Execute a derived task and persist a matching import_runs row.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
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
