<?php

namespace App\Services\Import;

use App\Models\DataSourceModel;
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
    public function __construct(
        private readonly ?ImportConfig $config = null,
        private readonly ?OccurrenceSourceAdapterFactory $adapterFactory = null,
        private readonly ?OccurrenceImportService $occurrenceImportService = null,
        private readonly ?ImportRunModel $importRunModel = null,
        private readonly ?DataSourceModel $dataSourceModel = null,
    ) {
    }

    /**
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

        $source = strtolower($sourceKey);
        $sourceAbbr = strtoupper($adapterFactory->sourceAbbr($source));

        $dataSource = $dataSourceModel->where('abbr', $sourceAbbr)->first();

        if ($dataSource === null) {
            throw new InvalidArgumentException('No data_sources row found for abbr: ' . $sourceAbbr);
        }

        $checkpoint = $checkpointOverride ?? $this->lastSuccessfulCheckpoint($importRunModel, $source);

        $runId = (int) $importRunModel->insert([
            'source_key' => $source,
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
                    break;
                }

                if ($counts['errors'] > 0) {
                    break;
                }
            }

            $status = $total['errors'] > 0 ? 'failed' : 'success';

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

    private function lastSuccessfulCheckpoint(ImportRunModel $importRunModel, string $sourceKey): ?string
    {
        $run = $importRunModel
            ->where('source_key', $sourceKey)
            ->whereIn('status', ['success', 'partial'])
            ->orderBy('id', 'desc')
            ->first();

        if ($run === null) {
            return null;
        }

        $checkpoint = $run['checkpoint'] ?? null;

        return is_string($checkpoint) && $checkpoint !== '' ? $checkpoint : null;
    }
}
