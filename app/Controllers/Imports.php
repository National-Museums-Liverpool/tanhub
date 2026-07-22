<?php

namespace App\Controllers;

use App\Models\ImportOffsetModel;
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
        'lookup:indicia:recording_schemes' => [
            'category' => 'Lookups',
            'label' => 'recording_schemes',
            'source' => 'indicia',
            'kind' => 'entity',
            'entity' => 'recording_schemes',
            'source_key' => 'indicia-taxonomy:recording_schemes',
            'supports_run' => true,
        ],
        'lookup:indicia:geographic_regions' => [
            'category' => 'Lookups',
            'label' => 'geographic_regions',
            'source' => 'indicia',
            'kind' => 'entity',
            'entity' => 'geographic_regions',
            'source_key' => 'indicia-taxonomy:geographic_regions',
            'supports_run' => true,
        ],
        'lookup:indicia:grid_square_stats' => [
            'category' => 'Lookups',
            'label' => 'grid_square_stats',
            'source' => 'indicia',
            'kind' => 'entity',
            'entity' => 'grid_square_stats',
            'source_key' => 'indicia-taxonomy:grid_square_stats',
            'supports_run' => true,
        ],
        'taxonomy:indicia:taxon_groups' => [
            'category' => 'Taxonomy',
            'label' => 'taxon_groups',
            'source' => 'indicia',
            'kind' => 'entity',
            'entity' => 'taxon_groups',
            'source_key' => 'indicia-taxonomy:taxon_groups',
            'supports_run' => true,
        ],
        'taxonomy:indicia:taxon_ranks' => [
            'category' => 'Taxonomy',
            'label' => 'taxon_ranks',
            'source' => 'indicia',
            'kind' => 'entity',
            'entity' => 'taxon_ranks',
            'source_key' => 'indicia-taxonomy:taxon_ranks',
            'supports_run' => true,
        ],
        'taxonomy:indicia:taxa' => [
            'category' => 'Taxonomy',
            'label' => 'taxa',
            'source' => 'indicia',
            'kind' => 'entity',
            'entity' => 'taxa',
            'source_key' => 'indicia-taxonomy:taxa',
            'supports_run' => true,
        ],
        'taxonomy:indicia:taxon_names' => [
            'category' => 'Taxonomy',
            'label' => 'taxon_names',
            'source' => 'indicia',
            'kind' => 'entity',
            'entity' => 'taxon_names',
            'source_key' => 'indicia-taxonomy:taxon_names',
            'supports_run' => true,
        ],
        'occurrence:indicia:occurrences' => [
            'category' => 'Occurrences',
            'label' => 'occurrences',
            'source' => 'indicia',
            'kind' => 'occurrence',
            'source_key' => 'indicia-occurrences:occurrences',
            'supports_run' => true,
        ],
        'occurrence:nbn:occurrences' => [
            'category' => 'Occurrences',
            'label' => 'occurrences',
            'source' => 'nbn',
            'kind' => 'unsupported',
            'source_key' => 'nbn-occurrences:occurrences',
            'supports_run' => false,
        ],
        'stats:derived:taxon_stats' => [
            'category' => 'Report stats',
            'label' => 'taxon_stats',
            'source' => null,
            'kind' => 'unsupported',
            'source_key' => 'derived-stats:taxon_stats',
            'supports_run' => false,
        ],
        'stats:derived:taxon_year_stats' => [
            'category' => 'Report stats',
            'label' => 'taxon_year_stats',
            'source' => null,
            'kind' => 'unsupported',
            'source_key' => 'derived-stats:taxon_year_stats',
            'supports_run' => false,
        ],
        'stats:derived:grid_square_stats_counts' => [
            'category' => 'Report stats',
            'label' => 'grid_square_stats_counts',
            'source' => null,
            'kind' => 'derived',
            'source_key' => 'derived-stats:grid_square_stats_counts',
            'supports_run' => true,
        ],
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const DEPENDENCIES = [
        'lookup:indicia:grid_square_stats' => ['lookup:indicia:geographic_regions'],
        'taxonomy:indicia:taxa' => [
            'lookup:indicia:recording_schemes',
            'lookup:indicia:geographic_regions',
            'taxonomy:indicia:taxon_groups',
            'taxonomy:indicia:taxon_ranks',
        ],
        'taxonomy:indicia:taxon_names' => ['taxonomy:indicia:taxa'],
        'occurrence:indicia:occurrences' => [
            'lookup:indicia:recording_schemes',
            'lookup:indicia:geographic_regions',
            'lookup:indicia:grid_square_stats',
            'taxonomy:indicia:taxon_groups',
            'taxonomy:indicia:taxon_ranks',
            'taxonomy:indicia:taxa',
            'taxonomy:indicia:taxon_names',
        ],
        'occurrence:nbn:occurrences' => [
            'lookup:indicia:recording_schemes',
            'lookup:indicia:geographic_regions',
            'lookup:indicia:grid_square_stats',
            'taxonomy:indicia:taxon_groups',
            'taxonomy:indicia:taxon_ranks',
            'taxonomy:indicia:taxa',
            'taxonomy:indicia:taxon_names',
        ],
        'stats:derived:taxon_stats' => ['occurrence:indicia:occurrences'],
        'stats:derived:taxon_year_stats' => ['occurrence:indicia:occurrences'],
        'stats:derived:grid_square_stats_counts' => [
            'lookup:indicia:grid_square_stats',
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
        $taskKey = trim((string) $this->request->getPost('task_key'));

        if (! isset(self::TASKS[$taskKey])) {
            return redirect()->to(site_url('imports'))->with('error', 'Unknown import task.');
        }

        $queueModel = model(ImportTaskQueueModel::class);

        if (! $this->isTaskQueued($queueModel, $taskKey)) {
            $queueModel->insert([
                'task_key' => $taskKey,
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

            $headTaskKey = (string) $nextQueued['task_key'];
            $taskStates = $this->buildTaskStates();
            $state = $taskStates[$headTaskKey] ?? null;

            if ($state === null) {
                $queueModel->update((int) $nextQueued['id'], [
                    'status' => 'failed',
                    'message' => 'Unknown task key in queue.',
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
                continue;
            }

            if ($state['blocked_by'] !== []) {
                $errorMessages[] = 'Queued task ' . $state['label'] . ' is blocked by: ' . implode(', ', $state['blocked_by']) . '.';
                break;
            }

            $queueModel->update((int) $nextQueued['id'], [
                'status' => 'running',
                'started_at' => date('Y-m-d H:i:s'),
                'message' => null,
            ]);

            if (! $state['supports_run']) {
                $errorMessages[] = 'Task ' . $state['label'] . ' is not implemented yet.';
                $queueModel->update((int) $nextQueued['id'], [
                    'status' => 'failed',
                    'message' => 'Task is not implemented yet.',
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
                continue;
            }

            try {
                $result = $this->runTask($state);
                $summary = $this->summarizeTaskResult($state, $result);
                $runStatus = strtolower((string) ($result['status'] ?? 'success'));
                $queueStatus = $runStatus === 'success' ? 'completed' : 'failed';

                if ($runStatus !== 'success') {
                    $errorMessages[] = $summary;
                } elseif (((int) ($result['skipped'] ?? 0)) > 0) {
                    $warningMessages[] = $summary;
                } else {
                    $infoMessages[] = $summary;
                }

                $queueModel->update((int) $nextQueued['id'], [
                    'status' => $queueStatus,
                    'message' => $summary,
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);

                if ($queueStatus === 'failed') {
                    break;
                }
            } catch (Throwable $exception) {
                $errorMessages[] = 'Task ' . $state['label'] . ' failed: ' . $exception->getMessage();
                $queueModel->update((int) $nextQueued['id'], [
                    'status' => 'failed',
                    'message' => $exception->getMessage(),
                    'finished_at' => date('Y-m-d H:i:s'),
                ]);
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
        $status = strtolower((string) ($result['status'] ?? 'success'));
        $fetched = (int) ($result['fetched'] ?? 0);
        $inserted = (int) ($result['inserted'] ?? 0);
        $updated = (int) ($result['updated'] ?? 0);
        $skipped = (int) ($result['skipped'] ?? 0);
        $errors = (int) ($result['errors'] ?? 0);

        $summary = sprintf(
            'Task %s finished with status %s. Fetched: %d, inserted: %d, updated: %d, skipped: %d, errors: %d.',
            (string) ($state['label'] ?? 'unknown'),
            $status,
            $fetched,
            $inserted,
            $updated,
            $skipped,
            $errors,
        );

        if ($errors > 0) {
            return $summary . ' Import stopped early because some records failed.';
        }

        if ($skipped > 0) {
            return $summary . ' Some records were skipped; review the import logs if this was unexpected.';
        }

        return $summary;
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

        foreach (self::TASKS as $taskKey => $task) {
            $sourceKey = (string) $task['source_key'];
            $offsetRow = $db->table('import_offsets')->where('source_key', $sourceKey)->get()->getRowArray();
            $isComplete = $offsetModel->isComplete($sourceKey);
            $nextOffset = $offsetRow['next_offset'] ?? null;
            $nextCheckpoint = $offsetRow['next_checkpoint'] ?? null;

            $states[$taskKey] = [
                'task_key' => $taskKey,
                'category' => $task['category'],
                'label' => $task['label'],
                'source' => $task['source'],
                'kind' => $task['kind'],
                'entity' => $task['entity'] ?? null,
                'source_key' => $sourceKey,
                'supports_run' => (bool) $task['supports_run'],
                'is_complete' => $isComplete,
                'next_offset' => is_scalar($nextOffset) ? (string) $nextOffset : null,
                'next_checkpoint' => is_scalar($nextCheckpoint) ? (string) $nextCheckpoint : null,
                'queue_status' => null,
                'blocked_by' => [],
            ];
        }

        foreach ($this->queueStatusByTaskKey() as $taskKey => $queueStatus) {
            if (! isset($states[$taskKey])) {
                continue;
            }

            $states[$taskKey]['queue_status'] = $queueStatus;
        }

        foreach (self::DEPENDENCIES as $taskKey => $dependencies) {
            if (! isset($states[$taskKey])) {
                continue;
            }

            $blockedBy = [];

            foreach ($dependencies as $dependencyTaskKey) {
                $dependencyState = $states[$dependencyTaskKey] ?? null;

                if ($dependencyState === null) {
                    continue;
                }

                if (! (bool) $dependencyState['is_complete']) {
                    $blockedBy[] = (string) $dependencyState['label'];
                }
            }

            $states[$taskKey]['blocked_by'] = $blockedBy;
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
            /** @var \App\Services\Stats\GridSquareStatsCountsService $service */
            $service = service('gridSquareStatsCountsService');

            return $service->run(false);
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
     * Return active queue status keyed by task key.
     *
     * @return array<string, string>
     */
    private function queueStatusByTaskKey(): array
    {
        $statusByTaskKey = [];

        foreach ($this->taskQueueRows() as $row) {
            $taskKey = (string) ($row['task_key'] ?? '');
            $status = (string) ($row['status'] ?? '');

            if ($taskKey === '' || $status === '') {
                continue;
            }

            $statusByTaskKey[$taskKey] = $status;
        }

        return $statusByTaskKey;
    }

    /**
     * Determine whether a task is already queued or running.
     *
     * @param ImportTaskQueueModel $queueModel Queue model.
     * @param string               $taskKey Task key.
     *
     * @return bool
     */
    private function isTaskQueued(ImportTaskQueueModel $queueModel, string $taskKey): bool
    {
        return $queueModel
            ->where('task_key', $taskKey)
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
}
