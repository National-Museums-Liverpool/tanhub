<?php

namespace App\Services\Import;

use App\Models\DataSourceModel;
use App\Models\ImportOffsetModel;
use App\Models\ImportRunModel;
use App\Services\Import\Adapter\ImportSourceAdapterFactory;
use App\Services\Import\Persistence\EntityImportService;
use Config\Import as ImportConfig;
use InvalidArgumentException;
use RuntimeException;

/**
 * Orchestrates import adapter fetch, persistence, and offset tracking.
 *
 * Drives a single taxonomy entity import (e.g. `taxa`, `recording_schemes`)
 * for one source: fetches a batch via {@see \App\Services\Import\Adapter\ImportSourceAdapterInterface}
 * (created by {@see \App\Services\Import\Adapter\ImportSourceAdapterFactory}),
 * persists it via {@see \App\Services\Import\Persistence\EntityImportService},
 * then advances the entity's offset/completion state in
 * {@see \App\Models\ImportOffsetModel} and records the run outcome in
 * {@see \App\Models\ImportRunModel}. This is the `entity`-kind counterpart to
 * {@see \App\Services\Import\ImportOrchestrator}, which handles occurrence imports.
 */
class EntityImportOrchestrator
{
    /**
     * Construct the orchestrator with its collaborators.
     *
     * All dependencies are optional and resolved from the container/config
     * when null, which keeps this class easy to unit test with fakes.
     *
     * @param ImportConfig|null                    $config               Import configuration.
     * @param ImportSourceAdapterFactory|null       $adapterFactory       Source adapter factory.
     * @param EntityImportService|null             $entityImportService  Entity persistence dispatcher.
     * @param ImportRunModel|null                   $importRunModel       Import run tracker model.
     * @param DataSourceModel|null                  $dataSourceModel      Data source model.
     * @param ImportOffsetModel|null                $importOffsetModel    Import offset/checkpoint model.
     */
    public function __construct(
        private readonly ?ImportConfig $config = null,
        private readonly ?ImportSourceAdapterFactory $adapterFactory = null,
        private readonly ?EntityImportService $entityImportService = null,
        private readonly ?ImportRunModel $importRunModel = null,
        private readonly ?DataSourceModel $dataSourceModel = null,
        private readonly ?ImportOffsetModel $importOffsetModel = null,
    ) {
    }

    /**
     * Execute one taxonomy entity import run for a source.
     *
     * Verifies that this entity's prerequisite entities are already fully
     * imported (see {@see self::assertDependenciesComplete()}), resolves the
     * data source row for the source's abbreviation, then fetches one batch
     * starting at either `$offsetOverride` or the last successful offset
     * recorded in {@see ImportOffsetModel}, and persists it via
     * {@see EntityImportService::import()}.
     *
     * Offset bookkeeping: the next offset is the current offset plus the
     * number of rows actually processed (capped at the number fetched, so a
     * persistence layer that reports processing more rows than were fetched
     * cannot advance the offset past what was actually read). The entity is
     * marked complete only when there were zero row errors and the adapter
     * reported no more pages (`$batch->hasMore === false`); any row error
     * marks the entity incomplete so the next run resumes and retries from
     * the current offset. In `$dryRun` mode, the offset and completion state
     * are never persisted, so repeated dry runs always start from the same
     * offset. Any row error also short-circuits `has_more` to `true` in the
     * result to signal the run should be retried.
     * Geography lookup tasks process one group per invocation with independent
     * offsets; their public completion flag covers every configured group.
     *
     * @param string   $sourceKey      Source key to import from (e.g. `indicia`).
     * @param string   $entity         Taxonomy entity to import (e.g. `taxa`,
     *                                 `recording_schemes`, `geographic_regions`).
     * @param int      $limit          Maximum records to fetch in this run.
     * @param bool     $dryRun         Whether persistence and offset/completion
     *                                 updates are disabled for this run.
     * @param int|null $offsetOverride Optional offset to fetch from instead of the
     *                                 last successful offset (e.g. for manual re-runs).
     * @param callable(int): void|null $onRunCreated Callback linking a newly created run to its request.
     *
     * @return array<string, int|string|bool|null> Result with `run_id`, `entity`,
     *         `status` (`success`|`failed`), `offset` (offset fetched from),
     *         `next_offset`, `has_more`, `fetched`, `inserted`, `updated`,
     *         `skipped`, `errors`, and the optional `geography_group`.
     *
     * @throws InvalidArgumentException When no `data_sources` row exists for the
     *                                  source's configured abbreviation.
     * @throws RuntimeException         When any dependency entity is incomplete, or
     *                                  when the adapter fetch or persistence step
     *                                  throws (the original exception is wrapped and
     *                                  the run is marked failed before rethrowing).
     */
    public function run(
        string $sourceKey,
        string $entity,
        int $limit,
        bool $dryRun = false,
        ?int $offsetOverride = null,
        ?callable $onRunCreated = null,
    ): array
    {
        $config = $this->config ?? config(ImportConfig::class);
        $adapterFactory = $this->adapterFactory ?? new ImportSourceAdapterFactory($config);
        $entityImportService = $this->entityImportService ?? new EntityImportService();
        $importRunModel = $this->importRunModel ?? model(ImportRunModel::class);
        $dataSourceModel = $this->dataSourceModel ?? model(DataSourceModel::class);
        $importOffsetModel = $this->importOffsetModel ?? model(ImportOffsetModel::class);

        $source = strtolower($sourceKey);
        $entityKey = strtolower($entity);
        $sourceEntityKey = $source . '-taxonomy:' . $entityKey;
        $progress = null;
        $group = null;
        $progressKey = $sourceEntityKey;
        if (in_array($entityKey, ['geographic_regions', 'grid_square_stats'], true)) {
            if ($offsetOverride !== null && count($config->geographicRegions) > 1) {
                throw new InvalidArgumentException('An offset override is ambiguous with multiple geography groups. Clear import offsets to restart population.');
            }
            $progress = new GeographicImportProgress($importOffsetModel, $sourceEntityKey, array_keys($config->geographicRegions));
        }

        $this->assertDependenciesComplete($importOffsetModel, $source, $entityKey);
        if ($progress !== null) {
            $group = $progress->select();
            $progressKey = $progress->key($group);
        }

        $sourceAbbr = strtoupper($adapterFactory->sourceAbbr($source));

        $dataSource = $dataSourceModel->where('abbr', $sourceAbbr)->first();

        if ($dataSource === null) {
            throw new InvalidArgumentException('No data_sources row found for abbr: ' . $sourceAbbr);
        }

        $offset = $offsetOverride === null ? $this->lastSuccessfulOffset($importOffsetModel, $progressKey) : $offsetOverride;

        $runId = (int) $importRunModel->insert([
            'source_key' => $sourceEntityKey,
            'source_abbr' => $sourceAbbr,
            'status' => 'running',
            'checkpoint' => (string) $offset,
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            if ($onRunCreated !== null) {
                $onRunCreated($runId);
            }
            $batch = $adapterFactory->make($source, $group)->fetchBatch($entityKey, $limit, $offset);
            $counts = $entityImportService->import($entityKey, $batch->rows, $dryRun);
            $processed = max(0, min((int) ($counts['processed'] ?? 0), (int) ($counts['fetched'] ?? 0)));
            $nextOffset = $offset + $processed;

            $status = $counts['errors'] > 0 ? 'failed' : 'success';
            $isComplete = $counts['errors'] === 0 && $batch->hasMore === false;

            if (! $dryRun) {
                $importOffsetModel->setOffset($progressKey, $nextOffset);
                if ($progress !== null) {
                    $isComplete = $progress->finish($group, $isComplete);
                } else {
                    $importOffsetModel->setCompletion($sourceEntityKey, $isComplete);
                }
            } elseif ($progress !== null) {
                $isComplete = $progress->isCompleteAfter($group, $isComplete);
            }

            $importRunModel->update($runId, [
                'status' => $status,
                'checkpoint' => (string) $nextOffset,
                'fetched_count' => $counts['fetched'],
                'inserted_count' => $counts['inserted'],
                'updated_count' => $counts['updated'],
                'skipped_count' => $counts['skipped'],
                'error_count' => $counts['errors'],
                'message' => ($group !== null ? 'Geography group: ' . $group . '. ' : '')
                    . ($dryRun ? 'Dry-run execution.' : ($counts['errors'] > 0 ? 'Import stopped on first row error. Re-run to continue from current offset.' : 'Batch completed.')),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

            return [
                'run_id' => $runId,
                'entity' => $entityKey,
                'geography_group' => $group,
                'status' => $status,
                'offset' => $offset,
                'next_offset' => $nextOffset,
                'has_more' => ! $isComplete,
                'fetched' => $counts['fetched'],
                'inserted' => $counts['inserted'],
                'updated' => $counts['updated'],
                'skipped' => $counts['skipped'],
                'errors' => $counts['errors'],
            ];
        } catch (\Throwable $exception) {
            if (! $dryRun) {
                if ($progress !== null) {
                    $progress->finish($group, false);
                } else {
                    $importOffsetModel->setCompletion($sourceEntityKey, false);
                }
            }

            $importRunModel->update($runId, [
                'status' => 'failed',
                'checkpoint' => (string) $offset,
                'error_count' => 1,
                'message' => ($group !== null ? 'Geography group: ' . $group . '. ' : '') . $exception->getMessage(),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

            throw new RuntimeException('Import failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Resolve the offset to resume from for an entity import.
     *
     * @param ImportOffsetModel $importOffsetModel Import offset/checkpoint model.
     * @param string             $sourceKey         Source/entity tracking key (e.g. `indicia-taxonomy:taxa`).
     *
     * @return int Last successful offset, or 0 when none is recorded.
     */
    private function lastSuccessfulOffset(ImportOffsetModel $importOffsetModel, string $sourceKey): int
    {
        return $importOffsetModel->getOffset($sourceKey);
    }

    /**
     * Ensure dependent taxonomy entities have been fully imported.
     *
     * Looks up each dependency returned by {@see self::dependenciesFor()} in
     * {@see ImportOffsetModel} and collects any that are not yet marked
     * complete, so the caller can refuse to run an import whose prerequisite
     * lookup data is missing (e.g. importing `taxa` before `recording_schemes`).
     *
     * @param ImportOffsetModel $importOffsetModel Import offset/checkpoint model.
     * @param string            $source Source key.
     * @param string            $entityKey Entity to import.
     *
     * @return void
     *
     * @throws RuntimeException When one or more dependency entities are incomplete.
     */
    private function assertDependenciesComplete(ImportOffsetModel $importOffsetModel, string $source, string $entityKey): void
    {
        $dependencies = $this->dependenciesFor($entityKey);

        if ($dependencies === []) {
            return;
        }

        $missing = [];

        foreach ($dependencies as $dependency) {
            $dependencySourceKey = $source . '-taxonomy:' . $dependency;

            if (! $importOffsetModel->isComplete($dependencySourceKey)) {
                $missing[] = $dependency;
            }
        }

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(
            'Cannot import ' . $entityKey . ' until these imports are complete: ' . implode(', ', $missing),
        );
    }

    /**
     * Resolve prerequisite entity imports for a taxonomy entity.
     *
     * @param string $entityKey Entity key.
     *
     * @return array<int, string>
     */
    private function dependenciesFor(string $entityKey): array
    {
        return match ($entityKey) {
            'grid_square_stats' => ['geographic_regions'],
            'taxa' => ['recording_schemes', 'geographic_regions', 'taxon_groups', 'taxon_ranks'],
            'taxon_names' => ['taxa'],
            default => [],
        };
    }
}