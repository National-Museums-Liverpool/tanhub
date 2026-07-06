<?php

namespace App\Services\Import;

use App\Models\DataSourceModel;
use App\Models\ImportOffsetModel;
use App\Models\ImportRunModel;
use App\Services\Import\Adapter\TaxonomySourceAdapterFactory;
use App\Services\Import\Persistence\TaxonomyImportService;
use Config\Import as ImportConfig;
use InvalidArgumentException;
use RuntimeException;

/**
 * Orchestrates taxonomy adapter fetch, persistence, and offset tracking.
 */
class TaxonomyImportOrchestrator
{
    public function __construct(
        private readonly ?ImportConfig $config = null,
        private readonly ?TaxonomySourceAdapterFactory $adapterFactory = null,
        private readonly ?TaxonomyImportService $taxonomyImportService = null,
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
        $adapterFactory = $this->adapterFactory ?? new TaxonomySourceAdapterFactory($config);
        $taxonomyImportService = $this->taxonomyImportService ?? new TaxonomyImportService();
        $importRunModel = $this->importRunModel ?? model(ImportRunModel::class);
        $dataSourceModel = $this->dataSourceModel ?? model(DataSourceModel::class);
        $importOffsetModel = $this->importOffsetModel ?? model(ImportOffsetModel::class);

        $source = strtolower($sourceKey);
        $entityKey = strtolower($entity);
        $sourceEntityKey = $source . '-taxonomy:' . $entityKey;
        $sourceAbbr = strtoupper($adapterFactory->sourceAbbr($source));

        $dataSource = $dataSourceModel->where('abbr', $sourceAbbr)->first();

        if ($dataSource === null) {
            throw new InvalidArgumentException('No data_sources row found for abbr: ' . $sourceAbbr);
        }
        log_message('info', 'Offset override: ' . ($offsetOverride === null ? 'null' : (string) $offsetOverride));
        $offset = $offsetOverride === NULL ? $this->lastSuccessfulOffset($importOffsetModel, $sourceEntityKey) : $offsetOverride;
        log_message('info', 'Starting taxonomy import for source: ' . $source . ', entity: ' . $entityKey . ', limit: ' . $limit . ', offset: ' . $offset . ', dry-run: ' . ($dryRun ? 'true' : 'false'));

        $runId = (int) $importRunModel->insert([
            'source_key' => $sourceEntityKey,
            'source_abbr' => $sourceAbbr,
            'status' => 'running',
            'checkpoint' => (string) $offset,
            'started_at' => date('Y-m-d H:i:s'),
        ]);

        try {
            $batch = $adapterFactory->make($source)->fetchBatch($entityKey, $limit, $offset);
            $counts = $taxonomyImportService->import($entityKey, $batch->rows, $dryRun);
            $processed = max(0, min((int) ($counts['processed'] ?? 0), (int) ($counts['fetched'] ?? 0)));
            $nextOffset = $offset + $processed;

            $status = $counts['errors'] > 0 ? 'failed' : 'success';

            if (! $dryRun) {
                $importOffsetModel->setOffset($sourceEntityKey, $nextOffset);
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
            $importRunModel->update($runId, [
                'status' => 'failed',
                'checkpoint' => (string) $offset,
                'error_count' => 1,
                'message' => $exception->getMessage(),
                'finished_at' => date('Y-m-d H:i:s'),
            ]);

            throw new RuntimeException('Taxonomy import failed: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private function lastSuccessfulOffset(ImportOffsetModel $importOffsetModel, string $sourceKey): int
    {
        return $importOffsetModel->getOffset($sourceKey);
    }
}
