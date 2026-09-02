<?php

namespace App\Services;

use App\Models\TaxonMediaBulkImportModel;
use App\Models\TaxonMediaBulkImportRowModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use Config\TaxonMediaImport;
use InvalidArgumentException;
use RuntimeException;

/**
 * Validates, stages, processes, and publishes bulk taxon media imports.
 */
class TaxonMediaBulkImportService
{
    /** @var array<int, string> */
    private const IDENTIFIER_HEADERS = ['taxon_identifier', 'scientific_name_identifier', 'scientific_name'];

    /** @var array<int, string> */
    private const OPTIONAL_HEADERS = ['alt_text', 'caption', 'attribution', 'license', 'sort_order', 'is_primary'];

    /**
     * Construct the bulk import service.
     *
     * @param TaxonMediaUploadService       $uploadService Media storage service.
     * @param TaxonMediaBulkImportModel     $importModel   Import header model.
     * @param TaxonMediaBulkImportRowModel  $rowModel      Staged row model.
     * @param TaxonMediaImport              $config        Import configuration.
     */
    public function __construct(
        private readonly TaxonMediaUploadService $uploadService,
        private readonly TaxonMediaBulkImportModel $importModel,
        private readonly TaxonMediaBulkImportRowModel $rowModel,
        private readonly TaxonMediaImport $config,
    ) {
    }

    /**
     * Create a draft from a CSV without requiring any photo uploads.
     *
     * @param UploadedFile $csv CSV upload.
     * @param int|null      $ownerUserId Shield user ID, or null for trusted legacy callers.
     * @return array<string, mixed> New draft summary.
     * @throws InvalidArgumentException When the CSV is invalid.
     * @throws RuntimeException When draft storage cannot be written.
     */
    public function createDraft(UploadedFile $csv, ?int $ownerUserId = null): array
    {
        if (! $csv->isValid() || $csv->hasMoved()) {
            throw new InvalidArgumentException('CSV upload is not valid.');
        }

        $parsed = $this->parseCsv((string) $csv->getTempName());
        $uuid = $this->createUuidV4();
        $directory = $this->stagingDirectory($uuid);
        $db = db_connect();

        try {
            if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
                throw new RuntimeException('Unable to create bulk import staging directory.');
            }

            $csvPath = $directory . DIRECTORY_SEPARATOR . 'import.csv';
            if (! copy((string) $csv->getTempName(), $csvPath)) {
                throw new RuntimeException('Unable to stage the CSV file.');
            }

            $db->transException(true)->transStart();
            $this->importModel->insert([
                'uuid' => $uuid,
                'owner_user_id' => $ownerUserId,
                'csv_filename' => (string) $csv->getClientName(),
                'csv_path' => $this->relativePath($csvPath),
                'status' => 'draft',
                'total_rows' => count($parsed['rows']),
                'processed_rows' => 0,
            ]);
            $importId = (int) $this->importModel->getInsertID();

            foreach ($parsed['rows'] as $row) {
                $row['import_id'] = $importId;
                $row['staged_path'] = null;
                $this->rowModel->insert($row);
            }
            $db->transComplete();

            $summary = $this->summary(array_merge([
                'id' => $importId,
                'uuid' => $uuid,
                'total_rows' => count($parsed['rows']),
                'processed_rows' => 0,
                'status' => 'draft',
            ], ['owner_user_id' => $ownerUserId]));
            $summary['uuid'] = $uuid;

            return $summary;
        } catch (\Throwable $exception) {
            $this->removeDirectory($directory);
            throw $exception;
        }
    }

    /**
     * Stage one photo against its expected CSV basename.
     *
     * @param int          $importId Import ID.
     * @param int|null      $ownerUserId Required owner, or null for trusted callers.
     * @param UploadedFile $photo One photo upload.
     * @return array<string, mixed> Staged file summary.
     * @throws InvalidArgumentException When ownership, state, name, or file validation fails.
     * @throws RuntimeException When the staged file cannot be copied.
     */
    public function stagePhoto(int $importId, ?int $ownerUserId, UploadedFile $photo): array
    {
        $import = $this->findOwned($importId, $ownerUserId);
        if (! in_array((string) $import['status'], ['draft', 'uploading'], true)) {
            throw new InvalidArgumentException('This import is no longer accepting files.');
        }
        if (! $photo->isValid() || $photo->hasMoved()) {
            throw new InvalidArgumentException('Photo upload is not valid.');
        }

        $filename = basename(str_replace('\\', '/', (string) $photo->getClientName()));
        $row = $this->rowModel->where('import_id', $importId)->where('photo_filename', $filename)->first();
        if (! is_array($row)) {
            throw new InvalidArgumentException('CSV photos must match the uploaded files exactly; photo is not referenced: ' . $filename);
        }
        if (! empty($row['staged_path']) && is_file($this->absolutePath((string) $row['staged_path']))) {
            throw new InvalidArgumentException('Photo has already been staged: ' . $filename);
        }

        $mimeType = $this->uploadService->validateSourcePath((string) $photo->getTempName());
        $bytes = (int) $photo->getSize();
        $stagedTotal = $this->rowModel->selectSum('staged_bytes')->where('import_id', $importId)->first();
        $stagedBytes = (int) ($stagedTotal['staged_bytes'] ?? 0);
        if ($stagedBytes + $bytes > $this->config->maxStagedBytes) {
            throw new InvalidArgumentException('The import exceeds the configured staged byte limit.');
        }

        $target = $this->stagingDirectory((string) $import['uuid']) . DIRECTORY_SEPARATOR . $filename;
        if (! copy((string) $photo->getTempName(), $target)) {
            throw new RuntimeException('Unable to stage photo file: ' . $filename);
        }
        $this->rowModel->update((int) $row['id'], [
            'staged_path' => $this->relativePath($target),
            'mime_type' => $mimeType,
            'staged_bytes' => $bytes,
            'checksum' => hash_file('sha256', $target),
            'status' => 'pending',
            'error_message' => null,
        ]);
        $this->importModel->update($importId, ['status' => 'uploading', 'error_message' => null]);

        return [
            'id' => (int) $row['id'], 'photo_filename' => $filename,
            'bytes' => $bytes, 'mime_type' => $mimeType, 'status' => 'staged',
        ];
    }

    /**
     * Validate every expected file and queue a complete draft.
     *
     * @param int      $importId Import ID.
     * @param int|null $ownerUserId Required owner, or null for trusted callers.
     * @return array<string, mixed> Preflight result with errors and file counts.
     */
    public function preflight(int $importId, ?int $ownerUserId = null): array
    {
        $import = $this->findOwned($importId, $ownerUserId);
        $rows = $this->rowModel->where('import_id', $importId)->orderBy('row_number', 'ASC')->findAll();
        $errors = [];
        $bytes = 0;
        foreach ($rows as $row) {
            try {
                $path = $this->absolutePath((string) ($row['staged_path'] ?? ''));
                $mimeType = $this->uploadService->validateSourcePath($path);
                $checksum = hash_file('sha256', $path);
                if ($checksum !== (string) ($row['checksum'] ?? '') || (int) filesize($path) !== (int) $row['staged_bytes']) {
                    throw new InvalidArgumentException('The staged file changed after upload.');
                }
                $bytes += (int) filesize($path);
                if ($mimeType !== (string) ($row['mime_type'] ?? '')) {
                    throw new InvalidArgumentException('The staged MIME type changed after upload.');
                }
            } catch (\Throwable $exception) {
                $errors[] = 'Row ' . (int) $row['row_number'] . ': ' . $exception->getMessage();
            }
        }
        if (count($rows) > $this->config->maxFiles || count($rows) > $this->config->maxRows) {
            $errors[] = 'The import exceeds the configured row or file limit.';
        }
        if ($bytes > $this->config->maxStagedBytes) {
            $errors[] = 'The import exceeds the configured staged byte limit.';
        }

        $report = ['valid' => $errors === [], 'errors' => $errors, 'files' => count($rows), 'bytes' => $bytes];
        $this->importModel->update($importId, ['validation_report' => json_encode($report), 'error_message' => $errors === [] ? null : implode(' ', $errors)]);
        return $report;
    }

    /**
     * Queue a valid draft for background processing.
     *
     * @param int      $importId Import ID.
     * @param int|null $ownerUserId Required owner, or null for trusted callers.
     * @return array<string, mixed> Queued import summary.
     * @throws InvalidArgumentException When preflight fails or the import is not editable.
     */
    public function finalize(int $importId, ?int $ownerUserId = null): array
    {
        $import = $this->findOwned($importId, $ownerUserId);
        if (! in_array((string) $import['status'], ['draft', 'uploading', 'failed'], true)) {
            throw new InvalidArgumentException('This import cannot be queued in its current state.');
        }
        $report = $this->preflight($importId, $ownerUserId);
        if (! $report['valid']) {
            throw new InvalidArgumentException('Import preflight failed: ' . implode(' ', $report['errors']));
        }
        $this->importModel->update($importId, ['status' => 'queued', 'error_message' => null, 'finished_at' => null]);
        return $this->summary(array_merge($import, ['status' => 'queued', 'error_message' => null]));
    }

    /**
     * Return an owned import and its per-file progress.
     *
     * @param int      $importId Import ID.
     * @param int|null $ownerUserId Required owner, or null for trusted callers.
     * @return array<string, mixed> Import and file status envelope.
     */
    public function status(int $importId, ?int $ownerUserId = null): array
    {
        $import = $this->findOwned($importId, $ownerUserId);
        $result = $this->summary($import);
        $result['uuid'] = $import['uuid'];
        $result['validation_report'] = $import['validation_report'] !== null ? json_decode((string) $import['validation_report'], true) : null;
        $result['files'] = array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'], 'row_number' => (int) $row['row_number'],
                'photo_filename' => (string) $row['photo_filename'],
                'status' => (string) $row['status'], 'staged' => ! empty($row['staged_path']),
                'bytes' => (int) ($row['staged_bytes'] ?? 0),
                'mime_type' => $row['mime_type'] ?? null,
                'error_message' => $row['error_message'] ?? null,
            ];
        }, $this->rowModel->where('import_id', $importId)->orderBy('row_number', 'ASC')->findAll());
        return $result;
    }

    /**
     * Resolve an import UUID for an authenticated owner.
     *
     * @param string   $uuid Import UUID.
     * @param int|null $ownerUserId Required owner, or null for trusted callers.
     * @return array<string, mixed> Import row.
     * @throws InvalidArgumentException When the UUID is missing or not owned.
     */
    public function importByUuid(string $uuid, ?int $ownerUserId = null): array
    {
        $import = $this->importModel->where('uuid', trim($uuid))->first();
        if (! is_array($import) || ($ownerUserId !== null && (int) ($import['owner_user_id'] ?? 0) !== $ownerUserId)) {
            throw new InvalidArgumentException('Bulk media import was not found.');
        }
        return $import;
    }

    /**
     * Cancel an owned draft or queued/failed import and remove staged media.
     *
     * @param int      $importId Import ID.
     * @param int|null $ownerUserId Required owner, or null for trusted callers.
     * @return array<string, mixed> Cancelled import summary.
     * @throws InvalidArgumentException When the import is missing or immutable.
     */
    public function cancel(int $importId, ?int $ownerUserId = null): array
    {
        $import = $this->findOwned($importId, $ownerUserId);
        if ((string) $import['status'] === 'published') {
            throw new InvalidArgumentException('A published import cannot be cancelled.');
        }
        $this->uploadService->discardBulkImport($importId);
        $this->rowModel->where('import_id', $importId)->set(['staged_path' => null, 'status' => 'cancelled'])->update();
        $this->importModel->update($importId, ['status' => 'cancelled', 'finished_at' => date('Y-m-d H:i:s')]);
        $this->removeDirectory($this->stagingDirectory((string) $import['uuid']));
        return $this->summary(array_merge($import, ['status' => 'cancelled']));
    }

    /**
     * Make a failed import queueable again after checking its staged files.
     *
     * @param int      $importId Import ID.
     * @param int|null $ownerUserId Required owner, or null for trusted callers.
     * @return array<string, mixed> Queued import summary.
     * @throws InvalidArgumentException When the import cannot be retried.
     */
    public function retry(int $importId, ?int $ownerUserId = null): array
    {
        $import = $this->findOwned($importId, $ownerUserId);
        if ((string) $import['status'] !== 'failed') {
            throw new InvalidArgumentException('Only failed imports can be retried.');
        }
        return $this->finalize($importId, $ownerUserId);
    }

    /**
     * Select the oldest queued import for an unattended worker.
     *
     * @return array<string, mixed>|null Queued import summary or null when idle.
     */
    public function runNextBatch(?int $limit = null): ?array
    {
        $stale = date('Y-m-d H:i:s', time() - $this->config->staleJobSeconds);
        $this->importModel->where('status', 'processing')->where('heartbeat_at <', $stale)->set(['status' => 'queued'])->update();
        $import = $this->importModel->where('status', 'queued')->orderBy('created_at', 'ASC')->first();
        return is_array($import) ? $this->runBatch((int) $import['id'], $limit) : null;
    }

    /**
     * Stage a complete import for legacy callers that already have all files.
     *
     * @param UploadedFile          $csv    CSV upload.
     * @param array<int, UploadedFile> $photos Photo uploads.
     * @return array<string, mixed> Queued import summary.
     * @throws \Throwable When any draft, upload, or preflight step fails.
     */
    public function stage(UploadedFile $csv, array $photos): array
    {
        $draft = $this->createDraft($csv);
        try {
            foreach ($photos as $photo) {
                $this->stagePhoto((int) $draft['id'], null, $photo);
            }
            return $this->finalize((int) $draft['id']);
        } catch (\Throwable $exception) {
            $this->cancel((int) $draft['id']);
            throw $exception;
        }
    }

    /**
     * Process one bounded worker batch and publish when all rows are complete.
     *
     * @param int      $importId Import header ID.
     * @param int|null $limit    Maximum rows to process, or configured batch size.
     *
     * @return array<string, mixed> Worker status and progress summary.
     *
     * @throws InvalidArgumentException When the import does not exist.
     */
    public function runBatch(int $importId, ?int $limit = null): array
    {
        $import = $this->importModel->find($importId);
        if (! is_array($import)) {
            throw new InvalidArgumentException('Bulk media import was not found.');
        }

        if ((string) $import['status'] === 'published') {
            return $this->summary($import);
        }

        if (! in_array((string) $import['status'], ['queued', 'processing'], true)) {
            throw new InvalidArgumentException('This import is not ready for worker processing.');
        }

        $this->importModel->update($importId, [
            'status' => 'processing', 'started_at' => $import['started_at'] ?? date('Y-m-d H:i:s'),
            'heartbeat_at' => date('Y-m-d H:i:s'), 'error_message' => null,
        ]);
        $lastId = max(0, (int) ($import['next_row_id'] ?? 0));
        $rows = $this->rowModel
            ->where('import_id', $importId)
            ->where('status', 'pending')
            ->where('id >', $lastId)
            ->orderBy('id', 'ASC')
            ->findAll(max(1, $limit ?? $this->config->workerBatchSize));

        foreach ($rows as $row) {
            try {
                $media = $this->uploadService->uploadStagedForTaxon(
                    (int) $row['taxon_id'],
                    $this->absolutePath((string) $row['staged_path']),
                    (string) $row['photo_filename'],
                    [
                        'alt_text' => $row['alt_text'], 'caption' => $row['caption'],
                        'attribution' => $row['attribution'], 'license' => $row['license'],
                        'sort_order' => $row['sort_order'], 'is_primary' => $row['is_primary'],
                    ],
                    $importId,
                );
                $this->rowModel->update((int) $row['id'], ['status' => 'processed', 'media_id' => $media['id'], 'error_message' => null]);
                $this->importModel->update($importId, ['processed_rows' => (int) $import['processed_rows'] + 1, 'next_row_id' => (int) $row['id']]);
                $this->importModel->update($importId, ['heartbeat_at' => date('Y-m-d H:i:s')]);
                $import['processed_rows'] = (int) $import['processed_rows'] + 1;
                $import['next_row_id'] = (int) $row['id'];
            } catch (\Throwable $exception) {
                $this->rowModel->update((int) $row['id'], ['status' => 'failed', 'error_message' => $exception->getMessage()]);
                $this->uploadService->discardBulkImport($importId);
                $this->rowModel->where('import_id', $importId)->set([
                    'status' => 'pending', 'media_id' => null, 'error_message' => null,
                ])->update();
                $this->importModel->update($importId, [
                    'status' => 'failed', 'processed_rows' => 0, 'next_row_id' => null,
                    'finished_at' => date('Y-m-d H:i:s'),
                    'error_message' => 'Row ' . $row['row_number'] . ': ' . $exception->getMessage(),
                ]);

                return $this->summary(array_merge($import, ['status' => 'failed', 'error_message' => $exception->getMessage()]));
            }
        }

        $pending = $this->rowModel->where('import_id', $importId)->where('status', 'pending')->countAllResults();
        if ($pending > 0) {
            $this->importModel->update($importId, ['status' => 'queued']);
            return $this->summary(array_merge($import, ['status' => 'queued']));
        }

        try {
            $this->publish($importId);
        } catch (\Throwable $exception) {
            $this->uploadService->discardBulkImport($importId);
            $this->rowModel->where('import_id', $importId)->set([
                'status' => 'pending', 'media_id' => null, 'error_message' => null,
            ])->update();
            $this->importModel->update($importId, [
                'status' => 'failed', 'processed_rows' => 0, 'next_row_id' => null,
                'finished_at' => date('Y-m-d H:i:s'), 'error_message' => $exception->getMessage(),
            ]);
            return $this->summary(array_merge($import, ['status' => 'failed', 'processed_rows' => 0, 'error_message' => $exception->getMessage()]));
        }

        return $this->summary(array_merge($import, ['status' => 'published', 'processed_rows' => $import['processed_rows']]));
    }

    /**
     * Return recent imports for the administration page.
     *
     * @return array<int, array<string, mixed>> Import rows newest first.
     */
    public function recentImports(?int $ownerUserId = null): array
    {
        $builder = $this->importModel->orderBy('id', 'DESC');
        if ($ownerUserId !== null) {
            $builder->where('owner_user_id', $ownerUserId);
        }
        return $builder->findAll(20);
    }

    /**
     * Parse and validate CSV structure and resolve every taxon.
     *
     * @param string $path CSV path.
     *
     * @return array{rows:array<int,array<string,mixed>>,filenames:array<string,bool>}
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new InvalidArgumentException('Unable to read the CSV file.');
        }

        try {
            $headers = fgetcsv($handle);
            if (! is_array($headers)) {
                throw new InvalidArgumentException('CSV must contain a header row.');
            }
            $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]);
            $headers = array_map(static fn ($header): string => trim((string) $header), $headers);
            if (preg_match('//u', implode('', $headers)) !== 1) {
                throw new InvalidArgumentException('CSV must be UTF-8 encoded.');
            }
            $this->validateHeaders($headers);
            $identifierHeader = array_values(array_intersect(self::IDENTIFIER_HEADERS, $headers))[0];
            $rows = [];
            $filenames = [];
            $rowNumber = 1;

            while (($values = fgetcsv($handle)) !== false) {
                $rowNumber++;
                if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                    continue;
                }
                if (count($values) !== count($headers)) {
                    throw new InvalidArgumentException('CSV row ' . $rowNumber . ' has the wrong number of columns.');
                }
                $data = array_combine($headers, array_map(static fn ($value): string => trim((string) $value), $values));
                $filename = (string) $data['photo_filename'];
                if ($filename === '' || in_array($filename, ['.', '..'], true) || $filename !== basename(str_replace('\\', '/', $filename))) {
                    throw new InvalidArgumentException('Row ' . $rowNumber . ' must contain a basename-only photo_filename.');
                }
                if (isset($filenames[$filename])) {
                    throw new InvalidArgumentException('Duplicate photo_filename: ' . $filename);
                }
                $filenames[$filename] = true;
                if (count($rows) >= $this->config->maxRows) {
                    throw new InvalidArgumentException('CSV exceeds the configured row limit.');
                }
                $rows[] = $this->resolveRow($data, $identifierHeader, $rowNumber);
            }
        } finally {
            fclose($handle);
        }

        if ($rows === []) {
            throw new InvalidArgumentException('CSV must contain at least one data row.');
        }

        return ['rows' => $rows, 'filenames' => $filenames];
    }

    /**
     * Find an import and enforce its owner when an HTTP caller supplies one.
     *
     * @param int      $importId Import ID.
     * @param int|null $ownerUserId Required owner, or null for trusted callers.
     * @return array<string, mixed> Import row.
     * @throws InvalidArgumentException When the import is missing or not owned.
     */
    private function findOwned(int $importId, ?int $ownerUserId): array
    {
        $import = $this->importModel->find($importId);
        if (! is_array($import) || ($ownerUserId !== null && (int) ($import['owner_user_id'] ?? 0) !== $ownerUserId)) {
            throw new InvalidArgumentException('Bulk media import was not found.');
        }
        return $import;
    }

    /**
     * Validate the exact supported CSV header set.
     *
     * @param array<int, string> $headers Header names.
     * @return void
     */
    private function validateHeaders(array $headers): void
    {
        if (count($headers) !== count(array_unique($headers))) {
            throw new InvalidArgumentException('CSV headers must not contain duplicates.');
        }
        $identifierHeaders = array_values(array_intersect(self::IDENTIFIER_HEADERS, $headers));
        $allowed = array_merge(self::IDENTIFIER_HEADERS, ['photo_filename'], self::OPTIONAL_HEADERS);
        if (count($identifierHeaders) !== 1 || ! in_array('photo_filename', $headers, true) || array_diff($headers, $allowed) !== []) {
            throw new InvalidArgumentException('CSV must contain exactly one identifier header, photo_filename, and supported optional headers.');
        }
    }

    /**
     * Validate uploaded photo names, MIME types, and duplicate handling.
     *
     * @param array<int, UploadedFile> $photos Photo uploads.
     * @return array<string, UploadedFile> Uploaded files keyed by basename.
     */
    private function validatePhotos(array $photos): array
    {
        $map = [];
        foreach ($photos as $photo) {
            if (! $photo instanceof UploadedFile || ! $photo->isValid() || $photo->hasMoved()) {
                throw new InvalidArgumentException('Every photo upload must be valid.');
            }
            $filename = basename(str_replace('\\', '/', (string) $photo->getClientName()));
            if ($filename === '' || isset($map[$filename])) {
                throw new InvalidArgumentException('Photo uploads must have unique basenames.');
            }
            $this->uploadService->validateSourcePath((string) $photo->getTempName());
            $map[$filename] = $photo;
        }

        return $map;
    }

    /**
     * Resolve one CSV row using direct taxa fields before accepted names.
     *
     * @param array<string, string> $data             CSV row.
     * @param string                $identifierHeader Identifier field in use.
     * @param int                   $rowNumber         CSV row number.
     * @return array<string, mixed> Staged row data.
     */
    private function resolveRow(array $data, string $identifierHeader, int $rowNumber): array
    {
        $value = trim((string) $data[$identifierHeader]);
        if ($value === '') {
            throw new InvalidArgumentException('Row ' . $rowNumber . ' has an empty taxon identifier.');
        }
        $db = db_connect();
        $direct = [];
        if ($db->fieldExists($identifierHeader, 'taxa')) {
            $direct = $db->table('taxa')->where('deleted_at', null)->where($identifierHeader, $value)->get()->getResultArray();
        }
        if (count($direct) > 1) {
            throw new InvalidArgumentException('Row ' . $rowNumber . ' matches multiple direct taxa.');
        }
        $taxonId = isset($direct[0]['id']) ? (int) $direct[0]['id'] : 0;
        if ($taxonId === 0) {
            $fallbackField = $identifierHeader === 'scientific_name' ? 'name' : 'given_name_identifier';
            $fallback = $db->table('taxon_names tn')
                ->select('tn.taxon_id, tn.accepted')
                ->join('taxa t', 't.id = tn.taxon_id AND t.deleted_at IS NULL')
                ->where('tn.deleted_at', null)
                ->where('tn.' . $fallbackField, $value)
                ->get()->getResultArray();
            $candidateTaxa = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['taxon_id'], $fallback)));
            if (count($candidateTaxa) === 1) {
                $taxonId = $candidateTaxa[0];
            } else {
                $acceptedRows = array_filter($fallback, static fn (array $row): bool => (int) ($row['accepted'] ?? 0) === 1);
                $acceptedTaxa = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['taxon_id'], $acceptedRows)));
                if (count($acceptedRows) === 1 && count($acceptedTaxa) === 1) {
                    $taxonId = $acceptedTaxa[0];
                }
            }
            if ($taxonId === 0) {
                throw new InvalidArgumentException('Row ' . $rowNumber . ' could not be disambiguated to one accepted fallback taxon.');
            }
        }

        $isPrimary = (string) ($data['is_primary'] ?? '0');
        if (! in_array($isPrimary, ['0', '1'], true)) {
            throw new InvalidArgumentException('Row ' . $rowNumber . ' is_primary must be 0 or 1.');
        }
        $sortOrder = (string) ($data['sort_order'] ?? '0');
        if ($sortOrder !== '' && (! ctype_digit($sortOrder) || (int) $sortOrder < 0)) {
            throw new InvalidArgumentException('Row ' . $rowNumber . ' sort_order must be a non-negative integer.');
        }
        foreach (['alt_text' => 500, 'attribution' => 255, 'license' => 100] as $field => $length) {
            if (strlen((string) ($data[$field] ?? '')) > $length) {
                throw new InvalidArgumentException('Row ' . $rowNumber . ' ' . $field . ' exceeds ' . $length . ' characters.');
            }
        }

        return [
            'row_number' => $rowNumber,
            'taxon_id' => $taxonId,
            'photo_filename' => (string) $data['photo_filename'],
            'alt_text' => ($data['alt_text'] ?? '') !== '' ? $data['alt_text'] : null,
            'caption' => ($data['caption'] ?? '') !== '' ? $data['caption'] : null,
            'attribution' => ($data['attribution'] ?? '') !== '' ? $data['attribution'] : null,
            'license' => ($data['license'] ?? '') !== '' ? $data['license'] : null,
            'sort_order' => $sortOrder === '' ? 0 : (int) $sortOrder,
            'is_primary' => (int) $isPrimary,
            'status' => 'pending',
        ];
    }

    /**
     * Publish processed rows atomically and apply the last CSV primary per taxon.
     *
     * @param int $importId Import header ID.
     * @return void
     */
    private function publish(int $importId): void
    {
        $db = db_connect();
        $import = $this->importModel->find($importId);
        $rows = $this->rowModel->where('import_id', $importId)->where('status', 'processed')->orderBy('row_number', 'ASC')->findAll();
        $winners = [];
        foreach ($rows as $row) {
            if ((int) $row['is_primary'] === 1) {
                $winners[(int) $row['taxon_id']] = (int) $row['media_id'];
            }
        }

        $db->transException(true)->transStart();
        foreach ($winners as $taxonId => $mediaId) {
            $db->table('taxon_media')->where('taxon_id', $taxonId)->update(['is_primary' => 0]);
            $db->table('taxon_media')->where('id', $mediaId)->update(['is_primary' => 1]);
        }
        $db->table('taxon_media')->where('bulk_import_id', $importId)->update(['bulk_import_id' => null]);
        $this->rowModel->where('import_id', $importId)->where('status', 'processed')->set('status', 'published')->update();
        $this->importModel->update($importId, ['status' => 'published', 'finished_at' => date('Y-m-d H:i:s'), 'processed_rows' => count($rows)]);
        $db->transComplete();

        if (is_array($import)) {
            $this->removeDirectory(dirname($this->absolutePath((string) $import['csv_path'])));
        }
    }

    /**
     * Build a compact worker result.
     *
     * @param array<string, mixed> $import Import header data.
     * @return array<string, mixed> Worker result.
     */
    private function summary(array $import): array
    {
        return [
            'id' => (int) $import['id'], 'status' => (string) $import['status'],
            'total_rows' => (int) $import['total_rows'], 'processed_rows' => (int) ($import['processed_rows'] ?? 0),
            'error_message' => $import['error_message'] ?? null,
        ];
    }

    /**
     * Resolve a relative staging path below writable.
     *
     * @param string $relativePath Relative path.
     * @return string Absolute path.
     */
    private function absolutePath(string $relativePath): string
    {
        $path = realpath(rtrim((string) WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($relativePath, '/\\'));
        $root = realpath((string) WRITEPATH);
        if ($path === false || $root === false || ! str_starts_with($path, rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Invalid staged media path.');
        }
        return $path;
    }

    /**
     * Return a relative path below writable.
     *
     * @param string $absolutePath Absolute path.
     * @return string Relative path.
     */
    private function relativePath(string $absolutePath): string
    {
        return ltrim(str_replace((string) WRITEPATH, '', $absolutePath), '/\\');
    }

    /**
     * Return an import staging directory.
     *
     * @param string $uuid Import UUID.
     * @return string Absolute directory path.
     */
    private function stagingDirectory(string $uuid): string
    {
        return rtrim((string) WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . trim($this->config->uploadSubdirectory, '/\\') . DIRECTORY_SEPARATOR . $uuid;
    }

    /**
     * Remove a directory tree used by a failed staging attempt.
     *
     * @param string $directory Directory to remove.
     * @return void
     */
    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }

    /**
     * Generate a UUID v4 for an import.
     *
     * @return string UUID value.
     */
    private function createUuidV4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
