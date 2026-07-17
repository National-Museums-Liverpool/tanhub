<?php

namespace App\Services\Import;

use App\Models\DataSourceModel;
use App\Models\ImportOffsetModel;
use App\Models\ImportRunModel;
use App\Services\Import\Adapter\OccurrenceSourceAdapterFactory;
use App\Services\Import\Persistence\OccurrenceImportService;
use Config\Import as ImportConfig;
use InvalidArgumentException;
use RuntimeException;

/**
 * Orchestrates adapter fetch, persistence, and checkpoint tracking.
 */
class ImportOrchestrator
{
    /**
     * Initialize occurrence import orchestration dependencies.
     *
     * @param ImportConfig|null                   $config Import configuration.
     * @param OccurrenceSourceAdapterFactory|null $adapterFactory Source adapter factory.
     * @param OccurrenceImportService|null        $occurrenceImportService Occurrence persistence service.
     * @param ImportRunModel|null                 $importRunModel Import run tracker model.
     * @param DataSourceModel|null                $dataSourceModel Data source model.
     * @param ImportOffsetModel|null              $importOffsetModel Import offset/checkpoint model.
     */
    public function __construct(
        private readonly ?ImportConfig $config = null,
        private readonly ?OccurrenceSourceAdapterFactory $adapterFactory = null,
        private readonly ?OccurrenceImportService $occurrenceImportService = null,
        private readonly ?ImportRunModel $importRunModel = null,
        private readonly ?DataSourceModel $dataSourceModel = null,
        private readonly ?ImportOffsetModel $importOffsetModel = null,
    ) {
    }

    /**
     * Execute an occurrence import run for a source.
     *
     * @param string      $sourceKey Source key to import from.
     * @param int         $limit Maximum records to process in this run.
     * @param int         $pageSize Source page size per fetch.
     * @param bool        $dryRun Whether persistence is disabled for this run.
     * @param string|null $checkpointOverride Optional checkpoint override.
     *
     * @return array<string, int|string|null>
     */
    public function run(
        string $sourceKey,
        int $limit,
        int $pageSize,
        bool $dryRun = false,
        ?string $checkpointOverride = null,
    ): array {
        $config = $this->config ?? config(ImportConfig::class);
        $adapterFactory = $this->adapterFactory ?? new OccurrenceSourceAdapterFactory($config);
        $occurrenceImportService = $this->occurrenceImportService ?? new OccurrenceImportService();
        $importRunModel = $this->importRunModel ?? model(ImportRunModel::class);
        $dataSourceModel = $this->dataSourceModel ?? model(DataSourceModel::class);
        $importOffsetModel = $this->importOffsetModel ?? model(ImportOffsetModel::class);

        $source = strtolower($sourceKey);
        $sourceEntityKey = $this->occurrenceSourceKey($source);

        $this->assertTaxonomyDependenciesComplete($importOffsetModel);

        $sourceAbbr = strtoupper($adapterFactory->sourceAbbr($source));

        $dataSource = $dataSourceModel->where('abbr', $sourceAbbr)->first();

        if ($dataSource === null) {
            throw new InvalidArgumentException('No data_sources row found for abbr: ' . $sourceAbbr);
        }

        $checkpoint = $checkpointOverride
            ?? $this->lastSuccessfulCheckpoint($importOffsetModel, $importRunModel, $source, $sourceEntityKey);

        $runId = (int) $importRunModel->insert([
            'source_key' => $sourceEntityKey,
            'source_abbr' => $sourceAbbr,
            'status' => 'running',
            'checkpoint' => $checkpoint,
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        $adapter = $adapterFactory->make($source);

        $total = [
            'fetched' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ];

        $processed = 0;
        $hasMore = true;

        try {
            while ($hasMore && $processed < $limit) {
                $batchLimit = min($pageSize, $limit - $processed);
                $page = $adapter->fetchPage($checkpoint, $batchLimit);

                $counts = $occurrenceImportService->import(
                    $page->records,
                    (int) $dataSource['id'],
                    $sourceAbbr,
                    $dryRun,
                );

                $total['fetched'] += $counts['fetched'];
                $total['inserted'] += $counts['inserted'];
                $total['updated'] += $counts['updated'];
                $total['skipped'] += $counts['skipped'];
                $total['errors'] += $counts['errors'];

                $processed += (int) ($counts['processed'] ?? 0);
                $checkpoint = $counts['errors'] > 0
                    ? ($counts['last_checkpoint'] ?? $checkpoint)
                    : ($counts['last_checkpoint'] ?? $page->nextCheckpoint ?? $checkpoint);
                $hasMore = $counts['errors'] > 0 ? true : ($page->hasMore && $counts['fetched'] > 0);

                if ($counts['fetched'] === 0) {
                    $hasMore = false;
                    break;
                }

                if ($counts['errors'] > 0) {
                    break;
                }
            }

            $status = $total['errors'] > 0 ? 'failed' : 'success';

            if (! $dryRun) {
                $importOffsetModel->setCheckpoint($sourceEntityKey, $checkpoint);
                $importOffsetModel->setCompletion($sourceEntityKey, $status === 'success' && $hasMore === false);
            }

            $importRunModel->update($runId, [
                'status' => $status,
                'checkpoint' => $checkpoint,
                'fetched_count' => $total['fetched'],
                'inserted_count' => $total['inserted'],
                'updated_count' => $total['updated'],
                'skipped_count' => $total['skipped'],
                'error_count' => $total['errors'],
                'message' => $dryRun ? 'Dry-run execution.' : ($total['errors'] > 0 ? 'Import stopped on first row error. Re-run to continue from the last successful checkpoint.' : null),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

            return [
                'run_id' => $runId,
                'status' => $status,
                'checkpoint' => $checkpoint,
                'fetched' => $total['fetched'],
                'inserted' => $total['inserted'],
                'updated' => $total['updated'],
                'skipped' => $total['skipped'],
                'errors' => $total['errors'],
            ];
        } catch (\Throwable $exception) {
            if (! $dryRun) {
                $importOffsetModel->setCheckpoint($sourceEntityKey, $checkpoint);
                $importOffsetModel->setCompletion($sourceEntityKey, false);
            }

            $importRunModel->update($runId, [
                'status' => 'failed',
                'checkpoint' => $checkpoint,
                'fetched_count' => $total['fetched'],
                'inserted_count' => $total['inserted'],
                'updated_count' => $total['updated'],
                'skipped_count' => $total['skipped'],
                'error_count' => $total['errors'] + 1,
                'message' => $exception->getMessage(),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

            throw new RuntimeException('Import failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Resolve the checkpoint to resume from for an occurrence import.
     *
     * @param ImportOffsetModel $importOffsetModel Import offset/checkpoint model.
     * @param ImportRunModel    $importRunModel Import run tracker model.
     * @param string            $source Source key.
     * @param string            $sourceEntityKey Source/entity tracking key.
     *
     * @return string|null Checkpoint token when available.
     */
    private function lastSuccessfulCheckpoint(
        ImportOffsetModel $importOffsetModel,
        ImportRunModel $importRunModel,
        string $source,
        string $sourceEntityKey,
    ): ?string
    {
        $checkpoint = $importOffsetModel->getCheckpoint($sourceEntityKey);

        if ($checkpoint !== null) {
            return $checkpoint;
        }

        // Backward compatibility for runs created before occurrence source-key normalization.
        foreach ([$sourceEntityKey, $source] as $legacySourceKey) {
            $run = $importRunModel
                ->where('source_key', $legacySourceKey)
                ->whereIn('status', ['success', 'partial'])
                ->orderBy('id', 'desc')
                ->first();

            if ($run === null) {
                continue;
            }

            $runCheckpoint = $run['checkpoint'] ?? null;

            if (is_string($runCheckpoint) && $runCheckpoint !== '') {
                return $runCheckpoint;
            }
        }

        return null;
    }

    /**
     * Build the occurrence source key used for run and checkpoint tracking.
     *
     * @param string $source Source key.
     *
     * @return string Canonical occurrence source/entity key.
     */
    private function occurrenceSourceKey(string $source): string
    {
        return $source . '-occurrences:occurrences';
    }

    /**
     * Ensure taxonomy prerequisites are complete before importing occurrences.
     *
     * @param ImportOffsetModel $importOffsetModel Import offset/checkpoint model.
     *
     * @return void
     */
    private function assertTaxonomyDependenciesComplete(ImportOffsetModel $importOffsetModel): void
    {
        $taxonomySource = strtolower((string) array_key_first((array) ($this->config?->taxonomySources ?? config(ImportConfig::class)->taxonomySources)));

        if ($taxonomySource === '') {
            $taxonomySource = 'indicia';
        }

        $requiredEntities = [
            'recording_schemes',
            'geographic_regions',
            'grid_square_stats',
            'taxon_groups',
            'taxon_ranks',
            'taxa',
            'taxon_names',
        ];

        $missing = [];

        foreach ($requiredEntities as $entity) {
            $sourceKey = $taxonomySource . '-taxonomy:' . $entity;

            if (! $importOffsetModel->isComplete($sourceKey)) {
                $missing[] = $entity;
            }
        }

        if ($missing === []) {
            return;
        }

        throw new RuntimeException(
            'Cannot import occurrences until these imports are complete: ' . implode(', ', $missing),
        );
    }
}
