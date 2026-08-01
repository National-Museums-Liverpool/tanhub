<?php

namespace App\Services\Import;

use App\Models\ImportOffsetModel;
use App\Models\ImportRunModel;
use Config\Import as ImportConfig;
use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

/**
 * Selects and executes the next automated import task.
 *
 * Used by the scheduled/CLI "auto" import entry point (see
 * {@see \App\Commands\ImportAuto}) to decide, without an operator picking a
 * task manually, which single import to run next: first any incomplete
 * taxonomy bootstrap task, then the least-recently-successful report/derived
 * task if it is stale, otherwise the least-recently-run occurrence source.
 * Delegates execution to {@see \App\Services\Import\EntityImportOrchestrator}
 * (via the `importOrchestrator` service), {@see \App\Services\Import\ImportOrchestrator}
 * (via `occurrenceImportOrchestrator`), or {@see DerivedImportRunner} depending
 * on the selected task's kind.
 */
class AutoImportService
{
    /**
     * Ordered taxonomy entity import source keys that must all be complete
     * before any report/occurrence task is considered. Order matters: the
     * first incomplete entry is selected next, so this also encodes the
     * dependency order between taxonomy entities (e.g. `taxa` after its
     * lookup tables, `taxon_names` after `taxa`).
     *
     * @var array<int, string>
     */
    private const BOOTSTRAP_TASKS = [
        'indicia-taxonomy:recording_schemes',
        'indicia-taxonomy:geographic_regions',
        'indicia-taxonomy:grid_square_stats',
        'indicia-taxonomy:taxon_groups',
        'indicia-taxonomy:taxon_ranks',
        'indicia-taxonomy:taxa',
        'indicia-taxonomy:taxon_names',
    ];

    /**
     * Derived/report statistics tasks considered for automated runs, each
     * naming its tracking `source_key` and the CodeIgniter service name that
     * implements it. Selection picks whichever of these has gone longest
     * without a successful run (see {@see self::leastRecentlySuccessful()}).
     *
     * @var array<int, array<string, string>>
     */
    private const REPORT_TASKS = [
        ['source_key' => 'derived-stats:grid_square_stats_counts', 'service' => 'gridSquareStatsCountsService'],
        ['source_key' => 'derived-stats:taxon_rarity', 'service' => 'taxonRarityService'],
        ['source_key' => 'derived-stats:taxon_stats', 'service' => 'taxonStatsService'],
        ['source_key' => 'derived-stats:taxon_year_stats', 'service' => 'taxonYearStatsService'],
    ];

    /**
     * Occurrence import source keys eligible for automated runs once all
     * bootstrap taxonomy tasks are complete. Selection picks whichever
     * source was least recently run successfully.
     *
     * @var array<int, string>
     */
    private const OCCURRENCE_TASKS = [
        'indicia-occurrences:occurrences',
        'nbn-occurrences:occurrences',
    ];

    /**
     * Create the automated import service.
     *
     * All dependencies are optional constructor-promoted properties resolved
     * lazily from the container when null, which keeps this service easy to
     * unit test with fakes/mocks while defaulting to the real CodeIgniter
     * services/models in production.
     *
     * @param ImportOffsetModel|null   $importOffsetModel   Completion state model.
     * @param ImportRunModel|null      $importRunModel       Run history model.
     * @param DerivedImportRunner|null $derivedImportRunner Derived task runner.
     */
    public function __construct(
        private readonly ?ImportOffsetModel $importOffsetModel = null,
        private readonly ?ImportRunModel $importRunModel = null,
        private readonly ?DerivedImportRunner $derivedImportRunner = null,
    ) {
    }

    /**
     * Select the next task according to the automation rules.
     *
     * Priority order: (1) the first not-yet-complete taxonomy bootstrap task,
     * in the fixed order of {@see self::BOOTSTRAP_TASKS}; (2) a report/derived
     * task, if the least-recently-successful one has not succeeded within the
     * last two hours (a "stale" threshold); (3) otherwise, whichever occurrence
     * source (`indicia` or `nbn`) was least recently run successfully. This
     * ordering ensures taxonomy lookups are always populated before occurrence
     * data is imported, and keeps report statistics reasonably fresh without
     * starving occurrence imports.
     *
     * @param DateTimeInterface|null $now Time used for stale-task comparison;
     *                                    defaults to the current time.
     *
     * @return array<string, mixed> Selected task metadata. Always includes
     *                              `source_key`, `kind` (`entity`|`derived`|`occurrence`),
     *                              and `reason` (human-readable explanation of why
     *                              this task was selected). `kind`-specific keys:
     *                              `source`/`entity` for `entity`, `service` for
     *                              `derived`, `source` for `occurrence`. `derived`
     *                              and `occurrence` selections also include `last_run`
     *                              (ISO timestamp string or null).
     */
    public function select(?DateTimeInterface $now = null): array
    {
        $offsetModel = $this->importOffsetModel ?? model(ImportOffsetModel::class);

        foreach (self::BOOTSTRAP_TASKS as $sourceKey) {
            if (! $offsetModel->isComplete($sourceKey)) {
                return [
                    'source_key' => $sourceKey,
                    'kind' => 'entity',
                    'source' => 'indicia',
                    'entity' => substr($sourceKey, strlen('indicia-taxonomy:')),
                    'reason' => 'required import is not complete',
                ];
            }
        }

        $now = $now ?? new DateTimeImmutable();
        $staleBefore = $now->modify('-2 hours');
        $reportSelection = $this->leastRecentlySuccessful(self::REPORT_TASKS);

        if ($reportSelection['last_run'] === null || $this->isBefore($reportSelection['last_run'], $staleBefore)) {
            return [
                'source_key' => $reportSelection['task']['source_key'],
                'kind' => 'derived',
                'service' => $reportSelection['task']['service'],
                'reason' => 'report statistics task has not run successfully within two hours',
                'last_run' => $reportSelection['last_run'],
            ];
        }

        $occurrenceSelection = $this->leastRecentlySuccessful(self::OCCURRENCE_TASKS);

        return [
            'source_key' => $occurrenceSelection['task'],
            'kind' => 'occurrence',
            'source' => str_starts_with($occurrenceSelection['task'], 'nbn-') ? 'nbn' : 'indicia',
            'reason' => 'occurrence source was least recently run',
            'last_run' => $occurrenceSelection['last_run'],
        ];
    }

    /**
     * Select and execute one automated import task.
     *
     * Resolves `limit`/`pageSize` to at least 1, falling back to the
     * configured defaults ({@see \Config\Import::$defaultLimit},
     * {@see \Config\Import::$defaultLimit::$defaultPageSize}) when zero/falsy
     * values are passed. Selects a task via {@see self::select()} unless one
     * is supplied, then dispatches to the orchestrator/runner matching the
     * task's `kind`.
     *
     * @param int                       $limit        Maximum records for the selected import.
     * @param int                       $pageSize     Occurrence page size.
     * @param bool                      $dryRun       Whether persistence is disabled.
     * @param array<string,mixed>|null  $selectedTask Previously selected task metadata
     *                                                (as returned by {@see self::select()});
     *                                                when null, a task is selected internally.
     *
     * @return array<string, mixed> Result with `task` (the selected task metadata)
     *                              and `result` (the orchestrator/runner's result array,
     *                              whose shape depends on the task kind; see
     *                              {@see \App\Services\Import\EntityImportOrchestrator::run()},
     *                              {@see \App\Services\Import\ImportOrchestrator::run()}, and
     *                              {@see DerivedImportRunner::run()}).
     *
     * @throws RuntimeException When the selected task's `kind` is not runnable.
     */
    public function run(int $limit, int $pageSize, bool $dryRun = false, ?array $selectedTask = null): array
    {
        $task = $selectedTask ?? $this->select();
        $config = config(ImportConfig::class);
        $limit = max(1, $limit ?: (int) $config->defaultLimit);
        $pageSize = max(1, $pageSize ?: (int) $config->defaultPageSize);

        if ($task['kind'] === 'entity') {
            $result = service('importOrchestrator')->run('indicia', $task['entity'], $limit, $dryRun, null);
        } elseif ($task['kind'] === 'occurrence') {
            $result = service('occurrenceImportOrchestrator')->run($task['source'], $limit, $pageSize, $dryRun, null);
        } elseif ($task['kind'] === 'derived') {
            $runner = $this->derivedImportRunner ?? service('derivedImportRunner');
            $result = $runner->run($task['source_key'], $task['service'], $dryRun);
        } else {
            throw new RuntimeException('Selected import task is not runnable.');
        }

        return [
            'task' => $task,
            'result' => $result,
        ];
    }

    /**
     * Find the least recently successful task in a deterministic list.
     *
     * Iterates the given task list in order, looking up each task's most
     * recent `success` run in {@see ImportRunModel}. A task that has never
     * succeeded (`last_run === null`) is treated as more stale than any task
     * with a recorded successful run, so it is preferred; ties are broken by
     * list order (the first entry wins unless a strictly earlier one is found).
     *
     * @param array<int, array<string, string>|string> $tasks Task definitions: either
     *                                                        a `source_key`/`service` map
     *                                                        (report tasks) or a bare
     *                                                        source key string (occurrence tasks).
     *
     * @return array{task: array<string, string>|string, last_run: string|null} The
     *         least-recently-successful task and its last successful run timestamp
     *         (null if it has never succeeded).
     */
    private function leastRecentlySuccessful(array $tasks): array
    {
        $runModel = $this->importRunModel ?? model(ImportRunModel::class);
        $selectedTask = $tasks[0];
        $selectedLastRun = null;

        foreach ($tasks as $index => $task) {
            $sourceKey = is_array($task) ? $task['source_key'] : $task;
            $row = $runModel
                ->where('source_key', $sourceKey)
                ->where('status', 'success')
                ->orderBy('finished_at', 'desc')
                ->first();
            $lastRun = is_array($row) && is_scalar($row['finished_at'] ?? null)
                ? (string) $row['finished_at']
                : null;

            if ($index === 0) {
                $selectedLastRun = $lastRun;
                continue;
            }

            if ($index > 0 && ($selectedLastRun !== null && ($lastRun === null || $lastRun < $selectedLastRun))) {
                $selectedTask = $task;
                $selectedLastRun = $lastRun;
            }
        }

        return ['task' => $selectedTask, 'last_run' => $selectedLastRun];
    }

    /**
     * Compare a stored database timestamp with a threshold.
     *
     * @param string             $value     Stored timestamp (e.g. `finished_at` from
     *                                      {@see ImportRunModel}).
     * @param DateTimeInterface $threshold Stale threshold.
     *
     * @return bool True when the timestamp is before the threshold.
     */
    private function isBefore(string $value, DateTimeInterface $threshold): bool
    {
        return new DateTimeImmutable($value) < $threshold;
    }
}