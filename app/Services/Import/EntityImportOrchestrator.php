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
 */
class EntityImportOrchestrator
{
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
     * @return array<string, int|string|null>
     */
    public function run(string $sourceKey, string $entity, int $limit, bool $dryRun = false, ?int $offsetOverride = null): array
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

        $this->assertDependenciesComplete($importOffsetModel, $source, $entityKey);

        $sourceAbbr = strtoupper($adapterFactory->sourceAbbr($source));

        $dataSource = $dataSourceModel->where('abbr', $sourceAbbr)->first();

        if ($dataSource === null) {
            throw new InvalidArgumentException('No data_sources row found for abbr: ' . $sourceAbbr);
        }

        $offset = $offsetOverride === null ? $this->lastSuccessfulOffset($importOffsetModel, $sourceEntityKey) : $offsetOverride;

        $runId = (int) $importRunModel->insert([
            'source_key' => $sourceEntityKey,
            'source_abbr' => $sourceAbbr,
            'status' => 'running',
            'checkpoint' => (string) $offset,
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $batch = $adapterFactory->make($source)->fetchBatch($entityKey, $limit, $offset);
            $counts = $entityImportService->import($entityKey, $batch->rows, $dryRun);
            $processed = max(0, min((int) ($counts['processed'] ?? 0), (int) ($counts['fetched'] ?? 0)));
            $nextOffset = $offset + $processed;

            $status = $counts['errors'] > 0 ? 'failed' : 'success';
            $isComplete = $counts['errors'] === 0 && $batch->hasMore === false;

            if (! $dryRun) {
                $importOffsetModel->setOffset($sourceEntityKey, $nextOffset);
                $importOffsetModel->setCompletion($sourceEntityKey, $isComplete);
            }

            $importRunModel->update($runId, [
                'status' => $status,
                'checkpoint' => (string) $nextOffset,
                'fetched_count' => $counts['fetched'],
                'inserted_count' => $counts['inserted'],
                'updated_count' => $counts['updated'],
                'skipped_count' => $counts['skipped'],
                'error_count' => $counts['errors'],
                'message' => $dryRun ? 'Dry-run execution.' : ($counts['errors'] > 0 ? 'Import stopped on first row error. Re-run to continue from current offset.' : null),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

            return [
                'run_id' => $runId,
                'entity' => $entityKey,
                'status' => $status,
                'offset' => $offset,
                'next_offset' => $nextOffset,
                'has_more' => $counts['errors'] > 0 ? true : $batch->hasMore,
                'fetched' => $counts['fetched'],
                'inserted' => $counts['inserted'],
                'updated' => $counts['updated'],
                'skipped' => $counts['skipped'],
                'errors' => $counts['errors'],
            ];
        } catch (\Throwable $exception) {
            if (! $dryRun) {
                $importOffsetModel->setCompletion($sourceEntityKey, false);
            }

            $importRunModel->update($runId, [
                'status' => 'failed',
                'checkpoint' => (string) $offset,
                'error_count' => 1,
                'message' => $exception->getMessage(),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

            throw new RuntimeException('Import failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function lastSuccessfulOffset(ImportOffsetModel $importOffsetModel, string $sourceKey): int
    {
        return $importOffsetModel->getOffset($sourceKey);
    }

    /**
     * Ensure dependent taxonomy entities have been fully imported.
     *
     * @param ImportOffsetModel $importOffsetModel Import offset/checkpoint model.
     * @param string            $source Source key.
     * @param string            $entityKey Entity to import.
     *
     * @return void
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