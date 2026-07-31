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
 */
class AutoImportService
{
    /** @var array<int, string> */
    private const BOOTSTRAP_TASKS = [
        'indicia-taxonomy:recording_schemes',
        'indicia-taxonomy:geographic_regions',
        'indicia-taxonomy:grid_square_stats',
        'indicia-taxonomy:taxon_groups',
        'indicia-taxonomy:taxon_ranks',
        'indicia-taxonomy:taxa',
        'indicia-taxonomy:taxon_names',
    ];

    /** @var array<int, array<string, string>> */
    private const REPORT_TASKS = [
        ['source_key' => 'derived-stats:grid_square_stats_counts', 'service' => 'gridSquareStatsCountsService'],
        ['source_key' => 'derived-stats:taxon_rarity', 'service' => 'taxonRarityService'],
        ['source_key' => 'derived-stats:taxon_stats', 'service' => 'taxonStatsService'],
        ['source_key' => 'derived-stats:taxon_year_stats', 'service' => 'taxonYearStatsService'],
    ];

    /** @var array<int, string> */
    private const OCCURRENCE_TASKS = [
        'indicia-occurrences:occurrences',
        'nbn-occurrences:occurrences',
    ];

    /**
     * Create the automated import service.
     *
     * @param ImportOffsetModel|null   $importOffsetModel Completion state model.
     * @param ImportRunModel|null      $importRunModel Run history model.
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
     * @param DateTimeInterface|null $now Time used for stale-task comparison.
     *
     * @return array<string, mixed> Selected task metadata.
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
     * @param int  $limit Maximum records for the selected import.
     * @param int  $pageSize Occurrence page size.
    * @param bool             $dryRun Whether persistence is disabled.
    * @param array<string,mixed>|null $selectedTask Previously selected task metadata.
     *
     * @return array<string, mixed> Selection and execution result.
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
     * @param array<int, array<string, string>|string> $tasks Task definitions.
     *
     * @return array{task: array<string, string>|string, last_run: string|null}
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
     * @param string $value Stored timestamp.
     * @param DateTimeInterface $threshold Stale threshold.
     *
     * @return bool True when the timestamp is before the threshold.
     */
    private function isBefore(string $value, DateTimeInterface $threshold): bool
    {
        return new DateTimeImmutable($value) < $threshold;
    }
}