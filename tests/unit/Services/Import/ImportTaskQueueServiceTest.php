<?php

namespace Tests;

use App\Commands\ImportAuto;
use App\Models\ImportOffsetModel;
use App\Services\Import\ImportLock;
use App\Services\Stats\TaxonStatsService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use RuntimeException;

/**
 * Exercise the shared queue against the test database without external imports.
 *
 * @internal
 */
final class ImportTaskQueueServiceTest extends CIUnitTestCase
{
    /**
     * Prepare an isolated queue and completed prerequisites.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Services::reset();
        $migrations = service('migrations');
        $migrations->setNamespace(null);
        $migrations->latest();
        $db = db_connect();
        $db->table('import_task_queue')->emptyTable();
        $db->table('import_runs')->emptyTable();
        $db->table('import_offsets')->emptyTable();
        foreach ([
            'recording_schemes', 'geographic_regions', 'grid_square_stats',
            'taxon_groups', 'taxon_ranks', 'taxa', 'taxon_names',
        ] as $entity) {
            model(ImportOffsetModel::class)->setCompletion('indicia-taxonomy:' . $entity, true);
        }
        foreach ([
            'indicia-occurrences:occurrences', 'nbn-occurrences:occurrences',
            'derived-stats:taxon_year_stats',
        ] as $key) {
            model(ImportOffsetModel::class)->setCompletion($key, true);
        }
    }

    /**
     * Release mocked services between tests.
     */
    protected function tearDown(): void
    {
        Services::reset();
        parent::tearDown();
    }

    /**
     * A scheduled invocation consumes the UI request and continues it across batches.
     */
    public function testScheduledCommandContinuesQueuedTaxonStatsUntilComplete(): void
    {
        $queue = service('importTaskQueueService');
        $queue->enqueue('derived-stats:taxon_stats');
        model(ImportOffsetModel::class)->setCompletion('derived-stats:taxon_stats', true);
        model(ImportOffsetModel::class)->setCheckpoint('derived-stats:taxon_stats', '4');
        $stats = $this->createMock(TaxonStatsService::class);
        $stats->expects($this->exactly(2))->method('run')->with(false)->willReturnOnConsecutiveCalls(
            ['status' => 'success', 'has_more' => true, 'processed' => 10],
            ['status' => 'success', 'has_more' => false, 'processed' => 5],
        );
        Services::injectMock('taxonStatsService', $stats);
        $lock = $this->createMock(ImportLock::class);
        $lock->expects($this->exactly(2))->method('acquire')->willReturn(true);
        $lock->expects($this->exactly(2))->method('release');
        Services::injectMock('importLock', $lock);
        $command = new ImportAuto(service('logger'), service('commands'));

        $command->run([]);
        $rows = $queue->rows();
        $this->assertCount(1, $rows);
        $this->assertSame('queued', $rows[0]['status']);
        $this->assertNotEmpty($rows[0]['run_id']);
        $this->assertTrue(model(ImportOffsetModel::class)->isComplete('derived-stats:taxon_stats'));

        $command->run([]);
        $this->assertSame([], $queue->rows());
        $this->assertTrue(model(ImportOffsetModel::class)->isComplete('derived-stats:taxon_stats'));
        $this->assertSame(2, db_connect()->table('import_runs')->countAllResults());
    }

    /**
     * A queue request is deduplicated and requests retain FIFO order.
     */
    public function testEnqueueDeduplicatesAndSelectsOldestRequest(): void
    {
        $queue = service('importTaskQueueService');
        $queue->enqueue('derived-stats:taxon_stats');
        $queue->enqueue('derived-stats:taxon_stats');
        $queue->enqueue('indicia-taxonomy:recording_schemes');

        $this->assertCount(2, $queue->rows());
        $this->assertSame('derived-stats:taxon_stats', $queue->select()['source_key']);
    }

    /**
     * Resolve dependencies without removing the requested parent.
     */
    public function testSelectAdvancesPrerequisitesBeforeRequestedTask(): void
    {
        $queue = service('importTaskQueueService');
        model(ImportOffsetModel::class)->setCompletion('derived-stats:taxon_year_stats', false);
        model(ImportOffsetModel::class)->setCompletion('nbn-occurrences:occurrences', false);
        $queue->enqueue('derived-stats:taxon_stats');

        $task = $queue->select();
        $this->assertSame('nbn-occurrences:occurrences', $task['source_key']);
        $this->assertSame('derived-stats:taxon_stats', $task['requested_source_key']);
        model(ImportOffsetModel::class)->setCompletion('nbn-occurrences:occurrences', true);
        $this->assertSame('derived-stats:taxon_year_stats', $queue->select()['source_key']);
        model(ImportOffsetModel::class)->setCompletion('derived-stats:taxon_year_stats', true);
        $this->assertSame('derived-stats:taxon_stats', $queue->select()['source_key']);
    }

    /**
     * A failed batch remains visible and cannot be silently retried by automation.
     */
    public function testFailedBatchRequiresRetryAndPreservesCheckpoint(): void
    {
        $queue = service('importTaskQueueService');
        $queue->enqueue('derived-stats:taxon_stats');
        model(ImportOffsetModel::class)->setCheckpoint('derived-stats:taxon_stats', '4');
        $task = service('autoImportService')->select();
        $stats = $this->createMock(TaxonStatsService::class);
        $stats->method('run')->willReturn(['status' => 'failed', 'errors' => 1]);
        Services::injectMock('taxonStatsService', $stats);

        service('autoImportService')->run(10, 10, false, $task);

        $row = $queue->rows()[0];
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('failed', $row['message']);
        $this->assertNull($queue->select());
        try {
            $queue->assertRunnable('derived-stats:taxon_stats');
            $this->fail('Failed work must require explicit retry.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Retry', $exception->getMessage());
        }
        $queue->retry((int) $row['id']);
        $this->assertSame('derived-stats:taxon_stats', $queue->select()['source_key']);
        $this->assertSame('4', model(ImportOffsetModel::class)->getCheckpoint('derived-stats:taxon_stats'));
    }

    /**
     * Exceptions mark both the linked run and its queue request failed.
     */
    public function testExceptionLeavesVisibleFailedRequestAndReleasesCommandLock(): void
    {
        $queue = service('importTaskQueueService');
        $queue->enqueue('derived-stats:taxon_stats');
        $stats = $this->createMock(TaxonStatsService::class);
        $stats->method('run')->willThrowException(new RuntimeException('Statistics database unavailable.'));
        Services::injectMock('taxonStatsService', $stats);
        $lock = $this->createMock(ImportLock::class);
        $lock->expects($this->once())->method('acquire')->willReturn(true);
        $lock->expects($this->once())->method('release');
        Services::injectMock('importLock', $lock);

        (new ImportAuto(service('logger'), service('commands')))->run([]);

        $row = $queue->rows()[0];
        $this->assertSame('failed', $row['status']);
        $this->assertSame('Statistics database unavailable.', $row['message']);
    }

    /**
     * Dry runs do not claim, delete or otherwise update queue rows.
     */
    public function testDryRunLeavesQueueUnchanged(): void
    {
        $queue = service('importTaskQueueService');
        $queue->enqueue('derived-stats:taxon_stats');
        $before = db_connect()->table('import_task_queue')->get()->getResultArray();
        $stats = $this->createMock(TaxonStatsService::class);
        $stats->expects($this->once())->method('run')->with(true)
            ->willReturn(['status' => 'success', 'has_more' => false]);
        Services::injectMock('taxonStatsService', $stats);

        service('autoImportService')->run(10, 10, true);

        $this->assertSame($before, db_connect()->table('import_task_queue')->get()->getResultArray());
    }

    /**
     * A cancelled request cannot be executed using previously selected metadata.
     */
    public function testRemovedRequestCannotBeClaimed(): void
    {
        $queue = service('importTaskQueueService');
        $queue->enqueue('derived-stats:taxon_stats');
        $task = service('autoImportService')->select();
        $queue->remove($task['queue_id']);
        $stats = $this->createMock(TaxonStatsService::class);
        $stats->expects($this->never())->method('run');
        Services::injectMock('taxonStatsService', $stats);

        $this->expectException(RuntimeException::class);
        service('autoImportService')->run(10, 10, false, $task);
    }

    /**
     * Interrupted running work is failed even if the old timeout has not elapsed.
     */
    public function testRecoveryUsesExclusiveLockOwnershipRatherThanAge(): void
    {
        $db = db_connect();
        $db->table('import_runs')->insert([
            'source_key' => 'derived-stats:taxon_stats', 'source_abbr' => 'LOCAL',
            'status' => 'running', 'started_at' => date('Y-m-d H:i:s'),
        ]);
        $runId = $db->insertID();
        $db->table('import_task_queue')->insert([
            'source_key' => 'derived-stats:taxon_stats', 'status' => 'running',
            'run_id' => $runId, 'started_at' => date('Y-m-d H:i:s'),
        ]);

        service('importTaskQueueService')->recover();

        $row = service('importTaskQueueService')->rows()[0];
        $this->assertSame('failed', $row['status']);
        $this->assertStringContainsString('execution lock is no longer held', $row['message']);
        $this->assertSame('failed', $db->table('import_runs')->where('id', $runId)->get()->getRowArray()['status']);
    }

    /**
     * Reconcile a batch that finished before its process updated the queue.
     */
    public function testRecoveryReconcilesSuccessfulCompletedRequest(): void
    {
        $db = db_connect();
        model(ImportOffsetModel::class)->setCompletion('derived-stats:taxon_stats', true);
        $db->table('import_runs')->insert([
            'source_key' => 'derived-stats:taxon_stats', 'source_abbr' => 'LOCAL',
            'status' => 'success',
        ]);
        $db->table('import_task_queue')->insert([
            'source_key' => 'derived-stats:taxon_stats', 'status' => 'running',
            'run_id' => $db->insertID(),
        ]);

        service('importTaskQueueService')->recover();

        $this->assertSame([], service('importTaskQueueService')->rows());
    }

    /**
     * Failed prerequisites do not block other independent queued requests.
     */
    public function testFailedPrerequisiteDoesNotStarveOtherRequests(): void
    {
        $db = db_connect();
        $db->table('import_task_queue')->insert([
            'source_key' => 'derived-stats:taxon_year_stats', 'status' => 'failed',
        ]);
        model(ImportOffsetModel::class)->setCompletion('derived-stats:taxon_year_stats', false);
        $queue = service('importTaskQueueService');
        $queue->enqueue('derived-stats:taxon_stats');
        $queue->enqueue('indicia-taxonomy:recording_schemes');

        $this->assertSame('indicia-taxonomy:recording_schemes', $queue->select()['source_key']);
        $this->assertCount(3, $queue->rows());
    }

    /**
     * Preserve actionable errors even if a runner throws before creating run history.
     */
    public function testEarlyExecutionFailureIsRecordedInHistory(): void
    {
        $queue = service('importTaskQueueService');
        $queue->enqueue('derived-stats:taxon_stats');
        $task = service('autoImportService')->select();

        try {
            $queue->run($task, static function (callable $onRunCreated): array {
                throw new RuntimeException('Missing import configuration.');
            });
            $this->fail('An execution failure must be surfaced.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Missing import configuration.', $exception->getMessage());
        }

        $row = $queue->rows()[0];
        $this->assertSame('failed', $row['status']);
        $this->assertSame('Missing import configuration.', $row['message']);
        $this->assertNotEmpty($row['run_id']);
    }

    /**
     * Retain occurrence requests when their runner reports an incomplete checkpoint.
     */
    public function testOccurrenceContinuationUsesPersistedCompletion(): void
    {
        $queue = service('importTaskQueueService');
        $queue->enqueue('nbn-occurrences:occurrences');
        model(ImportOffsetModel::class)->setCompletion('nbn-occurrences:occurrences', false);
        $task = service('autoImportService')->select();
        $queue->run($task, static fn (callable $onRunCreated): array => ['status' => 'success']);
        $this->assertSame('queued', $queue->rows()[0]['status']);

        model(ImportOffsetModel::class)->setCompletion('nbn-occurrences:occurrences', true);
        $queue->run($task, static fn (callable $onRunCreated): array => ['status' => 'success']);
        $this->assertSame([], $queue->rows());
    }

    /**
     * Ignore failed parent requests when choosing other queued work, including failed prerequisites.
     */
    public function testFailureOfPrerequisitePausesItsAutomaticExecution(): void
    {
        $db = db_connect();
        $db->table('import_runs')->insert([
            'source_key' => 'derived-stats:taxon_year_stats', 'source_abbr' => 'LOCAL',
            'status' => 'failed', 'message' => 'Annual statistics failed.',
        ]);
        $db->table('import_task_queue')->insert([
            'source_key' => 'derived-stats:taxon_stats', 'status' => 'failed',
            'run_id' => $db->insertID(),
        ]);
        $task = [
            'source_key' => 'derived-stats:taxon_year_stats',
            'kind' => 'derived', 'service' => 'taxonYearStatsService',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('awaiting Retry');
        service('autoImportService')->run(10, 10, false, $task);
    }
}
