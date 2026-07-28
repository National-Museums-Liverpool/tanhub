<?php

namespace Tests;

use App\Services\Import\EntityImportOrchestrator;
use App\Services\Stats\GridSquareStatsCountsService;
use App\Services\Stats\TaxonRarityService;
use App\Services\Stats\TaxonStatsService;
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
        $result->assertSee('taxon_rarity');
        $result->assertSee('Not implemented');
    }

    public function testRunBlockedTaskShowsError(): void
    {
        $this->authenticateAs('imports-admin-blocked@example.com', 'admin');

        $result = $this->post('imports/run', [
            'source_key' => 'indicia-taxonomy:taxa',
        ]);

        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('imports'));
        $result->assertSessionHas('error');

        $queueRows = db_connect()
            ->table('import_task_queue')
            ->where('source_key', 'indicia-taxonomy:taxa')
            ->get()
            ->getResultArray();

        $this->assertCount(0, $queueRows);
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
            'source_key' => 'indicia-taxonomy:taxon_names',
        ]);

        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('imports'));
        $result->assertSessionHas('message');

        $queueRow = db_connect()
            ->table('import_task_queue')
            ->where('source_key', 'indicia-taxonomy:taxon_names')
            ->orderBy('id', 'desc')
            ->get()
            ->getRowArray();

        $this->assertNull($queueRow);
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
            'source_key' => 'indicia-taxonomy:taxon_names',
        ]);

        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('imports'));
        $result->assertSessionHas('warning');

        $queueRow = db_connect()
            ->table('import_task_queue')
            ->where('source_key', 'indicia-taxonomy:taxon_names')
            ->orderBy('id', 'desc')
            ->get()
            ->getRowArray();

        $this->assertNull($queueRow);
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
            'source_key' => 'indicia-taxonomy:taxon_names',
        ]);

        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('imports'));
        $result->assertSessionHas('error');

        $queueRow = db_connect()
            ->table('import_task_queue')
            ->where('source_key', 'indicia-taxonomy:taxon_names')
            ->orderBy('id', 'desc')
            ->get()
            ->getRowArray();

        $this->assertNull($queueRow);
    }

    public function testRunDerivedGridSquareStatsCountsTask(): void
    {
        $this->markTaxonomyDependenciesComplete();

        $db = db_connect();
        $db->table('import_offsets')->where('source_key', 'indicia-occurrences:occurrences')->update(['is_complete' => 1]);
        $db->table('import_offsets')->insert([
            'source_key' => 'nbn-occurrences:occurrences',
            'next_offset' => 0,
            'next_checkpoint' => null,
            'is_complete' => 1,
        ]);

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
            'source_key' => 'derived-stats:grid_square_stats_counts',
        ]);

        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('imports'));
        $result->assertSessionHas('message');

        $queueRow = $db->table('import_task_queue')
            ->where('source_key', 'derived-stats:grid_square_stats_counts')
            ->orderBy('id', 'desc')
            ->get()
            ->getRowArray();

        $this->assertNull($queueRow);

        $runRow = $db->table('import_runs')
            ->where('source_key', 'derived-stats:grid_square_stats_counts')
            ->orderBy('id', 'desc')
            ->get()
            ->getRowArray();

        $this->assertIsArray($runRow);
        $this->assertSame('success', (string) $runRow['status']);
        $this->assertSame(5, (int) $runRow['fetched_count']);
        $this->assertSame(3, (int) $runRow['updated_count']);
    }

    public function testRunDerivedTaxonRarityTask(): void
    {
        $this->markTaxonomyDependenciesComplete();

        $db = db_connect();
        $db->table('import_offsets')->where('source_key', 'indicia-occurrences:occurrences')->update(['is_complete' => 1]);

        $this->authenticateAs('imports-admin-rarity@example.com', 'admin');

        $mock = $this->createMock(TaxonRarityService::class);
        $mock->expects($this->once())
            ->method('run')
            ->with(false)
            ->willReturn([
                'status' => 'success',
                'fetched' => 8,
                'inserted' => 0,
                'updated' => 6,
                'skipped' => 2,
                'errors' => 0,
            ]);

        \Config\Services::injectMock('taxonRarityService', $mock);

        $result = $this->post('imports/run', [
            'source_key' => 'derived-stats:taxon_rarity',
        ]);

        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('imports'));
        $result->assertSessionHas('warning');

        $queueRow = $db->table('import_task_queue')
            ->where('source_key', 'derived-stats:taxon_rarity')
            ->orderBy('id', 'desc')
            ->get()
            ->getRowArray();

        $this->assertNull($queueRow);

        $runRow = $db->table('import_runs')
            ->where('source_key', 'derived-stats:taxon_rarity')
            ->orderBy('id', 'desc')
            ->get()
            ->getRowArray();

        $this->assertIsArray($runRow);
        $this->assertSame('success', (string) $runRow['status']);
        $this->assertSame(8, (int) $runRow['fetched_count']);
        $this->assertSame(2, (int) $runRow['skipped_count']);
    }

    public function testRunDerivedTaxonStatsTask(): void
    {
        $this->markTaxonomyDependenciesComplete();

        $db = db_connect();
        $db->table('import_offsets')->where('source_key', 'indicia-occurrences:occurrences')->update(['is_complete' => 1]);

        $this->authenticateAs('imports-admin-taxon-stats@example.com', 'admin');

        $mock = $this->createMock(TaxonStatsService::class);
        $mock->expects($this->once())
            ->method('run')
            ->with(false)
            ->willReturn([
                'status' => 'success',
                'fetched' => 6,
                'processed' => 6,
                'inserted' => 6,
                'updated' => 0,
                'not changed' => 0,
                'skipped' => 0,
                'errors' => 0,
            ]);

        \Config\Services::injectMock('taxonStatsService', $mock);

        $result = $this->post('imports/run', [
            'source_key' => 'derived-stats:taxon_stats',
        ]);

        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('imports'));
        $result->assertSessionHas('message');

        $queueRow = $db->table('import_task_queue')
            ->where('source_key', 'derived-stats:taxon_stats')
            ->orderBy('id', 'desc')
            ->get()
            ->getRowArray();

        $this->assertNull($queueRow);

        $runRow = $db->table('import_runs')
            ->where('source_key', 'derived-stats:taxon_stats')
            ->orderBy('id', 'desc')
            ->get()
            ->getRowArray();

        $this->assertIsArray($runRow);
        $this->assertSame('success', (string) $runRow['status']);
        $this->assertSame(6, (int) $runRow['fetched_count']);
        $this->assertSame(6, (int) $runRow['inserted_count']);
    }

    private function seedImportOffsets(): void
    {
        $db = db_connect();
        $db->table('import_offsets')->emptyTable();
        $db->table('import_task_queue')->emptyTable();
        $db->table('import_runs')->emptyTable();

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
