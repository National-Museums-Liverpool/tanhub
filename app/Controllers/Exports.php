<?php

namespace App\Controllers;

use App\Services\Export\ExportBusyException;
use App\Services\Export\ExportTemplate;
use App\Services\Search\TaxonSearchService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\ResponseInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Owner-only staff export confirmation, progress and browser-driven processing.
 */
class Exports extends BaseController
{
    /**
     * Display recent downloads and perform bounded opportunistic cleanup.
     *
     * @return string Rendered owned history.
     */
    public function index(): string
    {
        $service = service('exportService');
        $cleanupError = null;
        try {
            $service->cleanup();
        } catch (ExportBusyException) {
            $cleanupError = 'Another export batch is running; cleanup will retry on your next visit.';
        } catch (Throwable $exception) {
            log_message('error', 'Export cleanup failed: {error}', ['error' => $exception->getMessage()]);
            $cleanupError = 'Export cleanup could not run. Storage has not been marked as reclaimed.';
        }
        return $this->renderPage('exports/index', ['pageTitle' => 'My downloads', 'bodyClass' => 'app-shell',
            'jobs' => $service->recent($this->owner()), 'configurationError' => $service->configurationError(),
            'cleanupError' => $cleanupError]);
    }

    /**
     * Confirm templates against the applied, not unsubmitted, grid state.
     *
     * @return string Rendered confirmation or explicit validation errors.
     */
    public function confirm(): string
    {
        $input = $this->request->getGet();
        $resource = $input['resource'] ?? '';
        if (! in_array($resource, ['taxa', 'occurrences'], true)) {
            throw PageNotFoundException::forPageNotFound();
        }
        $estimatedTotal = null;
        $estimateError = [];
        try {
            $estimatedTotal = $this->estimatedTotal($input['estimated_total'] ?? null);
        } catch (InvalidArgumentException $exception) {
            $estimateError['estimated_total'] = $exception->getMessage();
        }
        unset($input['resource'], $input['page'], $input['estimated_total']);
        $search = new TaxonSearchService();
        $criteria = $search->criteria($input, $resource === 'taxa');
        $ordering = $search->ordering($input, $resource === 'taxa');
        $errors = $criteria->errors + $ordering['errors'] + $estimateError;
        if ($errors !== []) {
            $this->response->setStatusCode(400);
        }
        return $this->renderPage('exports/confirm', ['pageTitle' => 'Download ' . $resource,
            'bodyClass' => 'app-shell', 'resource' => $resource,
            'criteria' => $criteria->values + ['sort' => $ordering['sort'], 'direction' => $ordering['direction']],
            'summary' => $search->summary($criteria, $ordering, $resource === 'taxa'),
            'estimatedTotal' => $estimatedTotal,
            'templates' => ExportTemplate::choices($resource === 'taxa'), 'errors' => $errors,
            'configurationError' => service('exportService')->configurationError()]);
    }

    /**
     * Create an owned export after server-side contract validation.
     *
     * @return ResponseInterface Redirect to progress or validation notification.
     */
    public function create(): ResponseInterface
    {
        try {
            $input = json_decode($this->postValue('criteria'), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($input)) {
                throw new InvalidArgumentException('Invalid export search criteria.');
            }
            $service = service('exportService');
            $service->cleanup();
            $job = $service->create($this->owner(), $this->postValue('resource'),
                $input, $this->postValue('template'),
                $this->estimatedTotal($this->request->getPost('estimated_total')));
            return redirect()->to(site_url('exports/' . $job['uuid']))->with('startExport', $job['uuid']);
        } catch (InvalidArgumentException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            log_message('error', 'Export creation failed: {error}', ['error' => $exception->getMessage()]);
            return redirect()->back()->with('error', 'The export could not be created. Check deployment limits and server logs.');
        }
    }

    /**
     * Show committed progress, automatically starting only the newly created export.
     *
     * @param string $uuid Job identifier.
     * @return string Rendered job details.
     */
    public function details(string $uuid): string
    {
        $job = $this->find($uuid);
        $contract = json_decode($job['contract'], true, 512, JSON_THROW_ON_ERROR);
        $templates = ExportTemplate::choices($job['resource'] === 'taxa');
        $newExport = session()->getFlashdata('startExport') === $uuid;
        if ($newExport) {
            session()->remove('startExport');
        }
        return $this->renderPage('exports/details', ['pageTitle' => 'Download progress', 'bodyClass' => 'app-shell',
            'job' => $job, 'contract' => $contract,
            'templateLabel' => $templates[$contract['template']] ?? 'Unavailable column template',
            'autoStart' => $newExport && $job['status'] === 'capturing']);
    }

    /**
     * Read owned status without processing or modifying a job.
     *
     * @param string $uuid Job identifier.
     * @return ResponseInterface Committed JSON status.
     */
    public function status(string $uuid): ResponseInterface
    {
        return $this->envelope($this->find($uuid));
    }

    /**
     * Process one CSRF-protected batch, supporting JavaScript and manual submission.
     *
     * @param string $uuid Job identifier.
     * @return ResponseInterface Updated progress or explicit failure.
     */
    public function process(string $uuid): ResponseInterface
    {
        $this->find($uuid);
        $owner = $this->owner();
        if ($this->request->isAJAX()) {
            session()->close();
        }
        try {
            $job = service('exportService')->process($uuid, $owner);
            return $this->request->isAJAX() ? $this->envelope($job)
                : redirect()->to(site_url('exports/' . $uuid));
        } catch (ExportBusyException $exception) {
            return $this->request->isAJAX()
                ? $this->envelope([], $exception->getMessage(), 409, true)
                : redirect()->to(site_url('exports/' . $uuid))->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            log_message('error', 'Export processing failed: {error}', ['error' => $exception->getMessage()]);
            $message = $exception instanceof InvalidArgumentException ? $exception->getMessage()
                : 'Export batch could not finish. Committed progress is preserved; check server logs/limits and Resume.';
            return $this->request->isAJAX()
                ? $this->envelope([], $message, 409)
                : redirect()->to(site_url('exports/' . $uuid))->with('error', $message);
        }
    }

    /**
     * Pause, cancel or regenerate an owned unfinished export.
     *
     * @param string $uuid Job identifier.
     * @return ResponseInterface Action progress or notification.
     */
    public function action(string $uuid): ResponseInterface
    {
        $this->find($uuid);
        try {
            $job = service('exportService')->action($uuid, $this->owner(), $this->postValue('action'));
            return $this->request->isAJAX() ? $this->envelope($job)
                : redirect()->to(site_url('exports/' . $uuid));
        } catch (Throwable $exception) {
            log_message('error', 'Export action failed: {error}', ['error' => $exception->getMessage()]);
            $message = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Export action could not finish. Please try again.';
            return $this->request->isAJAX() ? $this->envelope([], $message, 409)
                : redirect()->to(site_url('exports/' . $uuid))->with('error', $message);
        }
    }

    /**
     * Stream a ready private artifact while holding delivery protection.
     *
     * @param string $uuid Job identifier.
     * @return ResponseInterface Protected file response or failure notification.
     */
    public function download(string $uuid): ResponseInterface
    {
        $this->find($uuid);
        $owner = $this->owner();
        session()->close();
        try {
            return service('exportService')->download($uuid, $owner);
        } catch (Throwable $exception) {
            log_message('error', 'Export download failed: {error}', ['error' => $exception->getMessage()]);
            return $this->response->setStatusCode(410)->setBody('The export is unavailable, expired or currently busy.');
        }
    }

    /**
     * Require opaque ownership without revealing another user's existence.
     *
     * @param string $uuid Job identifier.
     * @return array<string, mixed> Owned checkpoint.
     */
    private function find(string $uuid): array
    {
        try {
            return service('exportService')->owned($uuid, $this->owner());
        } catch (InvalidArgumentException) {
            throw PageNotFoundException::forPageNotFound();
        }
    }

    /**
     * Read the authenticated staff identifier after route filters.
     *
     * @return int Current Shield user ID.
     */
    private function owner(): int
    {
        $user = auth()->user();
        if ($user === null || ! $user->inGroup('admin', 'manager')) {
            throw PageNotFoundException::forPageNotFound();
        }
        return (int) $user->id;
    }

    /**
     * Reject malformed multi-valued form fields before parsing or casting.
     *
     * @param string $key Required POST field.
     * @return string Single submitted value.
     */
    private function postValue(string $key): string
    {
        $value = $this->request->getPost($key);
        if (! is_string($value)) {
            throw new InvalidArgumentException('Enter a single export ' . $key . ' value.');
        }
        return $value;
    }

    /**
     * Validate an optional grid count without using it to limit export membership.
     *
     * @param mixed $value Submitted estimate, or null when unavailable.
     * @return int|null Non-negative estimated total.
     */
    private function estimatedTotal(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)
            || ($total = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]])) === false) {
            throw new InvalidArgumentException('Enter a non-negative estimated export row count.');
        }
        return $total;
    }

    /**
     * Return progress with rotated CSRF state and no private query payload.
     *
     * @param array<string, mixed> $job Committed checkpoint.
     * @param string|null $error Explicit public failure.
     * @param int $status HTTP status.
     * @param bool $retryable Whether normal contention permits an automatic retry.
     * @return ResponseInterface Stable JSON response.
     */
    private function envelope(array $job, ?string $error = null, int $status = 200, bool $retryable = false): ResponseInterface
    {
        if (isset($job['contract'])) {
            $contract = json_decode($job['contract'], true, 512, JSON_THROW_ON_ERROR);
            $job['estimated_total'] = $contract['estimated_total'] ?? null;
        }
        unset($job['contract'], $job['write_cursor'], $job['owner_id']);
        return $this->response->setStatusCode($status)->setHeader('Cache-Control', 'no-store')->setJSON([
            'data' => $job, 'error' => $error, 'retryable' => $retryable,
            'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }
}
