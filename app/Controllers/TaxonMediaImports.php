<?php

namespace App\Controllers;

use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Staff controller for staging bulk taxon media imports.
 */
class TaxonMediaImports extends BaseController
{
    /**
     * Show the bulk media import form and recent import status.
     *
     * @return string Rendered bulk import page.
     */
    public function index(): string
    {
        return $this->renderPage('taxon-media-imports/index', [
            'pageTitle' => 'Bulk taxon media import',
            'metaDescription' => 'Stage a CSV and matching taxon media files.',
            'bodyClass' => 'app-shell',
            'imports' => service('taxonMediaBulkImportService')->recentImports($this->userId()),
        ]);
    }

    /**
     * Validate and stage a CSV with its matching photo files.
     *
     * @return RedirectResponse Redirect to the import status page.
     */
    public function store(): RedirectResponse
    {
        $csv = $this->request->getFile('csv_file');
        $photos = $this->request->getFileMultiple('photos');

        if (! $csv instanceof UploadedFile || $csv->getError() === UPLOAD_ERR_NO_FILE) {
            return redirect()->back()->withInput()->with('error', 'Please choose a CSV file.');
        }

        if (! is_array($photos) || $photos === []) {
            return redirect()->back()->withInput()->with('error', 'Please choose all photo files referenced by the CSV.');
        }

        try {
            $service = service('taxonMediaBulkImportService');
            $result = $service->createDraft($csv, $this->userId());
            foreach ($photos as $photo) {
                $service->stagePhoto((int) $result['id'], $this->userId(), $photo);
            }
            $result = $service->finalize((int) $result['id'], $this->userId());
        } catch (InvalidArgumentException $exception) {
            return redirect()->back()->withInput()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            log_message('error', 'Bulk taxon media staging failed: ' . $exception->getMessage());

            return redirect()->back()->withInput()->with('error', 'Bulk media import could not be staged.');
        }

        return redirect()->to(site_url('taxon-media-imports'))->with(
            'message',
            'Import ' . esc((string) $result['id']) . ' queued for background processing.',
        );
    }

    /**
     * Create an owned draft from a CSV upload.
     *
     * @return ResponseInterface JSON draft envelope.
     */
    public function create(): ResponseInterface
    {
        $csv = $this->request->getFile('csv_file');
        if (! $csv instanceof UploadedFile || $csv->getError() === UPLOAD_ERR_NO_FILE) {
            return $this->jsonError('Please choose a CSV file.', 422);
        }
        try {
            return $this->jsonData(service('taxonMediaBulkImportService')->createDraft($csv, $this->userId()), 201);
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            log_message('error', 'Bulk taxon media draft failed: ' . $exception->getMessage());
            return $this->jsonError('Bulk media import could not be created.', 500);
        }
    }

    /**
     * Stage one photo in an owned draft.
     *
     * @param string $uuid Draft UUID.
     * @return ResponseInterface JSON file envelope.
     */
    public function upload(string $uuid): ResponseInterface
    {
        $photo = $this->request->getFile('photo') ?? $this->request->getFile('file');
        if (! $photo instanceof UploadedFile || $photo->getError() === UPLOAD_ERR_NO_FILE) {
            return $this->jsonError('Please choose one photo file.', 422);
        }
        try {
            $service = service('taxonMediaBulkImportService');
            $import = $service->importByUuid($uuid, $this->userId());
            return $this->jsonData($service->stagePhoto((int) $import['id'], $this->userId(), $photo));
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            log_message('error', 'Bulk taxon media file staging failed: ' . $exception->getMessage());
            return $this->jsonError('Photo could not be staged.', 500);
        }
    }

    /**
     * Preflight and queue an owned draft.
     *
     * @param string $uuid Draft UUID.
     * @return ResponseInterface JSON queue envelope.
     */
    public function finalize(string $uuid): ResponseInterface
    {
        try {
            $service = service('taxonMediaBulkImportService');
            $import = $service->importByUuid($uuid, $this->userId());
            return $this->jsonData($service->finalize((int) $import['id'], $this->userId()));
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            log_message('error', 'Bulk taxon media preflight failed: ' . $exception->getMessage());
            return $this->jsonError('Import could not be queued.', 500);
        }
    }

    /**
     * Return owned import and per-file status.
     *
     * @param string $uuid Import UUID.
     * @return ResponseInterface JSON status envelope.
     */
    public function status(string $uuid): ResponseInterface
    {
        try {
            $service = service('taxonMediaBulkImportService');
            $import = $service->importByUuid($uuid, $this->userId());
            return $this->jsonData($service->status((int) $import['id'], $this->userId()));
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), 404);
        }
    }

    /**
     * Process one photo for an owned queued import.
     *
     * @param string $uuid Import UUID.
     * @return ResponseInterface JSON import status envelope.
     */
    public function process(string $uuid): ResponseInterface
    {
        $lock = service('importLock');
        try {
            $service = service('taxonMediaBulkImportService');
            $import = $service->importByUuid($uuid, $this->userId());
            if (! $lock->acquire()) {
                $status = $service->status((int) $import['id'], $this->userId());
                $status['busy'] = true;

                return $this->jsonData($status);
            }

            try {
                $service->runBatch((int) $import['id'], 1);
            } finally {
                $lock->release();
            }

            return $this->jsonData($service->status((int) $import['id'], $this->userId()));
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            log_message('error', 'Bulk taxon media processing failed: ' . $exception->getMessage());
            return $this->jsonError('The next photo could not be processed.', 500);
        }
    }

    /**
     * Cancel an owned import.
     *
     * @param string $uuid Import UUID.
     * @return ResponseInterface JSON cancellation envelope.
     */
    public function cancel(string $uuid): ResponseInterface
    {
        return $this->changeImport($uuid, 'cancel', 'Import could not be cancelled.');
    }

    /**
     * Retry an owned failed import.
     *
     * @param string $uuid Import UUID.
     * @return ResponseInterface JSON retry envelope.
     */
    public function retry(string $uuid): ResponseInterface
    {
        return $this->changeImport($uuid, 'retry', 'Import could not be retried.');
    }

    /**
     * Return the latest validation report for an owned import.
     *
     * @param string $uuid Import UUID.
     * @return ResponseInterface JSON report envelope.
     */
    public function report(string $uuid): ResponseInterface
    {
        return $this->status($uuid);
    }

    /**
     * Apply one service lifecycle action to an owned import.
     *
     * @param string $uuid Import UUID.
     * @param string $action Service method name.
     * @param string $fallback Generic error message.
     * @return ResponseInterface JSON result envelope.
     */
    private function changeImport(string $uuid, string $action, string $fallback): ResponseInterface
    {
        try {
            $service = service('taxonMediaBulkImportService');
            $import = $service->importByUuid($uuid, $this->userId());
            return $this->jsonData($service->{$action}((int) $import['id'], $this->userId()));
        } catch (InvalidArgumentException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            log_message('error', 'Bulk taxon media lifecycle failed: ' . $exception->getMessage());
            return $this->jsonError($fallback, 500);
        }
    }

    /**
     * Return the current authenticated Shield user ID.
     *
     * @return int Authenticated user ID.
     * @throws InvalidArgumentException When no authenticated user exists.
     */
    private function userId(): int
    {
        $user = auth()->user();
        if ($user === null || (int) $user->id <= 0) {
            throw new InvalidArgumentException('Authentication is required.');
        }
        return (int) $user->id;
    }

    /**
     * Build a successful JSON envelope and refresh CSRF state.
     *
     * @param array<string, mixed> $data Response data.
     * @param int                  $status HTTP status.
     * @return ResponseInterface JSON response.
     */
    private function jsonData(array $data, int $status = 200): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'data' => $data, 'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }

    /**
     * Build a stable JSON error envelope and refresh CSRF state.
     *
     * @param string $message Public error message.
     * @param int    $status HTTP status.
     * @return ResponseInterface JSON response.
     */
    private function jsonError(string $message, int $status): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'error' => ['message' => $message], 'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }
}
