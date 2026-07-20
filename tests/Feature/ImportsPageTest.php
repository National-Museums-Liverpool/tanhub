<?php

namespace Tests;

use App\Services\Import\EntityImportOrchestrator;
use App\Services\Stats\GridSquareStatsCountsService;
use Config\Auth;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class ImportsPageTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

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

        $migrate = service('migrations');
        $migrate->setNamespace(null);
        $migrate->latest();

        $this->seedImportOffsets();
    }

    public function testImportsPageRequiresLogin(): void
    {
        $result = $this->get('imports');

        $result->assertStatus(302);
        $result->assertRedirect();
    }

    public function testImportsPageShowsBlockedDependenciesForManager(): void
    {
        $this->authenticateAs('imports-manager@example.com', 'manager');

        $result = $this->get('imports');

        $result->assertStatus(200);
        $result->assertSee('Imports');
        $result->assertSee('Blocked by taxon_groups');
        $result->assertSee('grid_square_stats_counts');
        $result->assertSee('Not implemented');
    }

    public function testRunBlockedTaskShowsError(): void
    {
        $this->authenticateAs('imports-admin-blocked@example.com', 'admin');

        $result = $this->post('imports/run', [
            'task_key' => 'taxonomy:indicia:taxa',
        ]);

        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('imports'));
        $result->assertSessionHas('error');

        $queueRows = db_connect()
            ->table('import_task_queue')
            ->where('task_key', 'taxonomy:indicia:taxa')
            ->get()
            ->getResultArray();

        $this->assertCount(1, $queueRows);
        $this->assertSame('queued', (string) $queueRows[0]['status']);
    }

    public function testRunUnblockedTaskQueuesAndRuns(): void
    {
        $this->markTaxonomyDependenciesComplete();

        $this->authenticateAs('imports-admin-run@example.com', 'admin');

        $mock = $this->createMock(EntityImportOrchestrator::class);
        $mock->expects($this->once())
            ->method('run')
            ->with('indicia', 'taxon_names', $this->greaterThan(0), false, null)
            ->willReturn([
                'status' => 'success',
                'run_id' => 99,
            ]);

        \Config\Services::injectMock('importOrchestrator', $mock);

        $result = $this->post('imports/run', [
            'task_key' => 'taxonomy:indicia:taxon_names',
        ]);

        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('imports'));
        $result->assertSessionHas('message');

        $queueRow = db_connect()
            ->table('import_task_queue')
            ->where('task_key', 'taxonomy:indicia:taxon_names')
            ->orderBy('id', 'desc')
            ->get()
            ->getRowArray();

        $this->assertIsArray($queueRow);
        $this->assertSame('completed', (string) $queueRow['status']);
    }

    public function testRunTaskWithSkippedRecordsShowsWarning(): void
    {
        $this->markTaxonomyDependenciesComplete();

        $this->authenticateAs('imports-admin-warning@example.com', 'admin');

        $mock = $this->createMock(EntityImportOrchestrator::class);
        $mock->expects($this->once())
            ->method('run')
            ->with('indicia', 'taxon_names', $this->greaterThan(0), false, null)
            ->willReturn([
                'status' => 'success',
                'run_id' => 100,
                'fetched' => 10,
                'inserted' => 8,
                'updated' => 0,
                'skipped' => 2,
                'errors' => 0,
            ]);

        \Config\Services::injectMock('importOrchestrator', $mock);

        $result = $this->post('imports/run', [
            'task_key' => 'taxonomy:indicia:taxon_names',
        ]);

        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('imports'));
        $result->assertSessionHas('warning');

        $queueRow = db_connect()
            ->table('import_task_queue')
            ->where('task_key', 'taxonomy:indicia:taxon_names')
            ->orderBy('id', 'desc')
            ->get()
            ->getRowArray();

        $this->assertIsArray($queueRow);
        $this->assertSame('completed', (string) $queueRow['status']);
        $this->assertStringContainsString('skipped: 2', strtolower((string) $queueRow['message']));
    }

    public function testRunTaskWithErrorsShowsErrorSummaryAndMarksQueueFailed(): void
    {
        $this->markTaxonomyDependenciesComplete();

        $this->authenticateAs('imports-admin-error@example.com', 'admin');

        $mock = $this->createMock(EntityImportOrchestrator::class);
        $mock->expects($this->once())
            ->method('run')
            ->with('indicia', 'taxon_names', $this->greaterThan(0), false, null)
            ->willReturn([
                'status' => 'failed',
                'run_id' => 101,
                'fetched' => 10,
                'inserted' => 7,
                'updated' => 0,
                'skipped' => 1,
                'errors' => 2,
            ]);

        \Config\Services::injectMock('importOrchestrator', $mock);

        $result = $this->post('imports/run', [
            'task_key' => 'taxonomy:indicia:taxon_names',
        ]);

        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('imports'));
        $result->assertSessionHas('error');

        $queueRow = db_connect()
            ->table('import_task_queue')
            ->where('task_key', 'taxonomy:indicia:taxon_names')
            ->orderBy('id', 'desc')
            ->get()
            ->getRowArray();

        $this->assertIsArray($queueRow);
        $this->assertSame('failed', (string) $queueRow['status']);
        $this->assertStringContainsString('errors: 2', strtolower((string) $queueRow['message']));
    }

    public function testRunDerivedGridSquareStatsCountsTask(): void
    {
        $this->markTaxonomyDependenciesComplete();

        $db = db_connect();
        $db->table('import_offsets')->where('source_key', 'indicia-occurrences:occurrences')->update(['is_complete' => 1]);

        $this->authenticateAs('imports-admin-derived@example.com', 'admin');

        $mock = $this->createMock(GridSquareStatsCountsService::class);
        $mock->expects($this->once())
            ->method('run')
            ->with(false)
            ->willReturn([
                'status' => 'success',
                'fetched' => 5,
                'inserted' => 0,
                'updated' => 3,
                'skipped' => 0,
                'errors' => 0,
            ]);

        \Config\Services::injectMock('gridSquareStatsCountsService', $mock);

        $result = $this->post('imports/run', [
            'task_key' => 'stats:derived:grid_square_stats_counts',
        ]);

        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('imports'));
        $result->assertSessionHas('message');

        $queueRow = $db->table('import_task_queue')
            ->where('task_key', 'stats:derived:grid_square_stats_counts')
            ->orderBy('id', 'desc')
            ->get()
            ->getRowArray();

        $this->assertIsArray($queueRow);
        $this->assertSame('completed', (string) $queueRow['status']);
    }

    private function seedImportOffsets(): void
    {
        $db = db_connect();
        $db->table('import_offsets')->emptyTable();
        $db->table('import_task_queue')->emptyTable();

        $db->table('import_offsets')->insertBatch([
            [
                'source_key' => 'indicia-taxonomy:recording_schemes',
                'next_offset' => 100,
                'next_checkpoint' => null,
                'is_complete' => 1,
            ],
            [
                'source_key' => 'indicia-taxonomy:geographic_regions',
                'next_offset' => 100,
                'next_checkpoint' => null,
                'is_complete' => 1,
            ],
            [
                'source_key' => 'indicia-taxonomy:taxon_groups',
                'next_offset' => 45,
                'next_checkpoint' => null,
                'is_complete' => 0,
            ],
            [
                'source_key' => 'indicia-taxonomy:taxon_ranks',
                'next_offset' => 100,
                'next_checkpoint' => null,
                'is_complete' => 1,
            ],
            [
                'source_key' => 'indicia-taxonomy:taxa',
                'next_offset' => 0,
                'next_checkpoint' => null,
                'is_complete' => 0,
            ],
            [
                'source_key' => 'indicia-taxonomy:taxon_names',
                'next_offset' => 0,
                'next_checkpoint' => null,
                'is_complete' => 0,
            ],
            [
                'source_key' => 'indicia-taxonomy:grid_square_stats',
                'next_offset' => 0,
                'next_checkpoint' => null,
                'is_complete' => 0,
            ],
            [
                'source_key' => 'indicia-occurrences:occurrences',
                'next_offset' => 0,
                'next_checkpoint' => 'abc123',
                'is_complete' => 0,
            ],
        ]);
    }

    private function markTaxonomyDependenciesComplete(): void
    {
        $db = db_connect();

        $db->table('import_offsets')
            ->whereIn('source_key', [
                'indicia-taxonomy:recording_schemes',
                'indicia-taxonomy:geographic_regions',
                'indicia-taxonomy:taxon_groups',
                'indicia-taxonomy:taxon_ranks',
                'indicia-taxonomy:taxa',
                'indicia-taxonomy:grid_square_stats',
            ])
            ->update([
                'is_complete' => 1,
            ]);
    }

    private function authenticateAs(string $email, string $group): void
    {
        $this->actingAs($this->makeUser($email, $group));
        $this->withSession($_SESSION);
    }

    private function makeUser(string $email, string $group)
    {
        /** @var UserModel $users */
        $users = model(setting('Auth.userProvider'));

        $user = $users->createNewUser([
            'username' => strstr($email, '@', true),
            'email' => $email,
            'password' => 'Password123!',
        ]);

        $users->save($user);

        $saved = $users->findById($users->getInsertID());
        $saved->activate();
        $users->save($saved);

        if ($group !== 'user') {
            $saved->addGroup($group);
        }

        return $saved;
    }
}
