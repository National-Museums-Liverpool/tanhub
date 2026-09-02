<?php

namespace Tests;

use App\Services\Import\ImportLock;
use App\Services\TaxonMediaBulkImportService;
use Config\Auth;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use InvalidArgumentException;

/**
 * @internal
 */
final class TaxonMediaImportsTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    /**
     * Reset application services and authentication before each request.
     */
    protected function setUp(): void
    {
        parent::setUp();

        \Config\Services::reset();
        $_SESSION = [];
        $_COOKIE = [];
        $this->withSession([]);

        if (function_exists('auth')) {
            try {
                auth()->logout();
            } catch (\Throwable) {
            }
        }

        config(Auth::class)->actions['register'] = null;
        service('migrations')->setNamespace(null)->latest();
    }

    /**
     * Ensure the media import endpoints require authentication.
     */
    public function testIndexRequiresLogin(): void
    {
        $result = $this->get('taxon-media-imports');

        $result->assertStatus(302);
        $result->assertRedirect();
    }

    /**
     * Ensure an authenticated manager can view recent imports.
     */
    public function testIndexShowsRecentImports(): void
    {
        $this->authenticateAs('media-import-manager@example.com', 'manager');

        $service = $this->createMock(TaxonMediaBulkImportService::class);
        $service->expects($this->once())
            ->method('recentImports')
            ->with($this->greaterThan(0))
            ->willReturn([]);
        \Config\Services::injectMock('taxonMediaBulkImportService', $service);

        $result = $this->get('taxon-media-imports');

        $result->assertStatus(200);
        $result->assertSee('Bulk taxon media import');
    }

    /**
     * Ensure draft creation rejects a request without a CSV upload.
     */
    public function testCreateRejectsMissingCsv(): void
    {
        $this->authenticateAs('media-import-create@example.com', 'manager');

        $result = $this->post('taxon-media-imports/create', $this->csrfFields());

        $this->assertJsonError($result, 422, 'Please choose a CSV file.');
    }

    /**
     * Ensure photo staging rejects a request without a photo upload.
     */
    public function testUploadRejectsMissingPhoto(): void
    {
        $this->authenticateAs('media-import-upload@example.com', 'manager');

        $result = $this->post('taxon-media-imports/example-uuid/files', $this->csrfFields());

        $this->assertJsonError($result, 422, 'Please choose one photo file.');
    }

    /**
     * Ensure status hides missing or unauthorised imports as not found.
     */
    public function testStatusReturnsNotFoundForMissingImport(): void
    {
        $this->authenticateAs('media-import-status@example.com', 'manager');

        $service = $this->createMock(TaxonMediaBulkImportService::class);
        $service->method('importByUuid')
            ->willThrowException(new InvalidArgumentException('Bulk media import was not found.'));
        \Config\Services::injectMock('taxonMediaBulkImportService', $service);

        $result = $this->get('taxon-media-imports/missing/status');

        $this->assertJsonError($result, 404, 'Bulk media import was not found.');
    }

    /**
     * Ensure finalizing an owned draft delegates and returns its queue summary.
     */
    public function testFinalizeReturnsQueuedImport(): void
    {
        $this->authenticateAs('media-import-finalize@example.com', 'manager');

        $service = $this->createMock(TaxonMediaBulkImportService::class);
        $service->expects($this->once())->method('importByUuid')->willReturn(['id' => 8]);
        $service->expects($this->once())->method('finalize')->with(8, $this->greaterThan(0))
            ->willReturn(['id' => 8, 'status' => 'queued']);
        \Config\Services::injectMock('taxonMediaBulkImportService', $service);

        $result = $this->post('taxon-media-imports/example-uuid/finalize', $this->csrfFields());
        $json = json_decode((string) $result->response()->getBody(), true);

        $result->assertStatus(200);
        $this->assertSame('queued', $json['data']['status']);
    }

    /**
     * Ensure processing releases the lock and returns refreshed status.
     */
    public function testProcessRunsBatchWhenLockIsAvailable(): void
    {
        $this->authenticateAs('media-import-process-success@example.com', 'manager');

        $service = $this->createMock(TaxonMediaBulkImportService::class);
        $service->expects($this->once())->method('importByUuid')->willReturn(['id' => 9]);
        $service->expects($this->once())->method('runBatch')->with(9, 1);
        $service->expects($this->once())->method('status')->with(9, $this->greaterThan(0))
            ->willReturn(['id' => 9, 'status' => 'complete']);
        \Config\Services::injectMock('taxonMediaBulkImportService', $service);

        $lock = $this->createMock(ImportLock::class);
        $lock->expects($this->once())->method('acquire')->willReturn(true);
        $lock->expects($this->once())->method('release');
        \Config\Services::injectMock('importLock', $lock);

        $result = $this->post('taxon-media-imports/example-uuid/process', $this->csrfFields());
        $json = json_decode((string) $result->response()->getBody(), true);

        $result->assertStatus(200);
        $this->assertSame('complete', $json['data']['status']);
    }

    /**
     * Ensure cancel and retry delegate their respective lifecycle actions.
     */
    public function testCancelAndRetryDelegateLifecycleActions(): void
    {
        $this->authenticateAs('media-import-lifecycle@example.com', 'manager');

        $service = $this->createMock(TaxonMediaBulkImportService::class);
        $service->expects($this->exactly(2))->method('importByUuid')->willReturn(['id' => 10]);
        $service->expects($this->once())->method('cancel')->with(10, $this->greaterThan(0))
            ->willReturn(['id' => 10, 'status' => 'cancelled']);
        $service->expects($this->once())->method('retry')->with(10, $this->greaterThan(0))
            ->willReturn(['id' => 10, 'status' => 'queued']);
        \Config\Services::injectMock('taxonMediaBulkImportService', $service);

        $cancel = $this->post('taxon-media-imports/example-uuid/cancel', $this->csrfFields());
        $cancelJson = json_decode((string) $cancel->response()->getBody(), true);
        $retry = $this->post('taxon-media-imports/example-uuid/retry', $this->csrfFields());
        $retryJson = json_decode((string) $retry->response()->getBody(), true);

        $cancel->assertStatus(200);
        $retry->assertStatus(200);
        $this->assertSame('cancelled', $cancelJson['data']['status']);
        $this->assertSame('queued', $retryJson['data']['status']);
    }

    /**
     * Ensure the report endpoint returns the same status envelope as status.
     */
    public function testReportReturnsImportStatus(): void
    {
        $this->authenticateAs('media-import-report@example.com', 'manager');

        $service = $this->createMock(TaxonMediaBulkImportService::class);
        $service->expects($this->once())->method('importByUuid')->willReturn(['id' => 11]);
        $service->expects($this->once())->method('status')->with(11, $this->greaterThan(0))
            ->willReturn(['id' => 11, 'status' => 'failed']);
        \Config\Services::injectMock('taxonMediaBulkImportService', $service);

        $result = $this->get('taxon-media-imports/example-uuid/report');
        $json = json_decode((string) $result->response()->getBody(), true);

        $result->assertStatus(200);
        $this->assertSame('failed', $json['data']['status']);
    }

    /**
     * Ensure processing reports a busy import when its lock is unavailable.
     */
    public function testProcessReportsBusyWhenLockCannotBeAcquired(): void
    {
        $this->authenticateAs('media-import-process@example.com', 'manager');

        $service = $this->createMock(TaxonMediaBulkImportService::class);
        $service->expects($this->once())
            ->method('importByUuid')
            ->willReturn(['id' => 7]);
        $service->expects($this->once())
            ->method('status')
            ->with(7, $this->greaterThan(0))
            ->willReturn(['id' => 7, 'status' => 'queued']);
        $service->expects($this->never())->method('runBatch');
        \Config\Services::injectMock('taxonMediaBulkImportService', $service);

        $lock = $this->createMock(ImportLock::class);
        $lock->expects($this->once())->method('acquire')->willReturn(false);
        $lock->expects($this->never())->method('release');
        \Config\Services::injectMock('importLock', $lock);

        $result = $this->post('taxon-media-imports/example-uuid/process', $this->csrfFields());
        $json = json_decode((string) $result->response()->getBody(), true);

        $result->assertStatus(200);
        $this->assertTrue($json['data']['busy']);
        $this->assertSame('queued', $json['data']['status']);
    }

    /**
     * Authenticate as a user and preserve session for feature requests.
     *
     * @param string $email User email.
     * @param string $group Group to assign.
     */
    private function authenticateAs(string $email, string $group): void
    {
        $this->actingAs($this->makeUser($email, $group));
        $this->withSession($_SESSION);
    }

    /**
     * Create and activate a test user.
     *
     * @param string $email User email.
     * @param string $group Group to assign.
    * @return User Activated user.
     */
    private function makeUser(string $email, string $group): User
    {
        /** @var UserModel $users */
        $users = model(setting('Auth.userProvider'));
        $user = $users->createNewUser([
            'username' => (string) strstr($email, '@', true),
            'email' => $email,
            'password' => 'Password123!',
        ]);
        $users->save($user);

        $saved = $users->findById($users->getInsertID());
        $saved->activate();
        $users->save($saved);
        $saved->addGroup($group);

        return $saved;
    }

    /**
     * Return CSRF fields required by protected POST routes.
     *
     * @return array<string, string> CSRF token name and hash.
     */
    private function csrfFields(): array
    {
        return [csrf_token() => csrf_hash()];
    }

    /**
     * Assert the standard JSON error response.
     *
     * @param mixed  $result Expected response wrapper.
     * @param int    $status Expected HTTP status.
     * @param string $message Expected public error message.
     */
    private function assertJsonError($result, int $status, string $message): void
    {
        $result->assertStatus($status);
        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame($message, $json['error']['message']);
        $this->assertArrayHasKey('csrf', $json);
    }
}