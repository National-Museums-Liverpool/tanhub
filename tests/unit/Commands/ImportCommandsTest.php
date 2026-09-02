<?php

namespace Tests;

use App\Commands\ImportAuto;
use App\Commands\ImportOccurrences;
use App\Services\Import\AutoImportService;
use App\Services\Import\ImportLock;
use App\Services\Import\ImportOrchestrator;
use CodeIgniter\Config\Services;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Verify import command parameter handling and delegation.
 */
final class ImportCommandsTest extends CIUnitTestCase
{
    /**
     * Verify occurrence imports require a source parameter.
     */
    public function testOccurrenceCommandRejectsMissingSource(): void
    {
        $orchestrator = $this->createMock(ImportOrchestrator::class);
        $orchestrator->expects($this->never())->method('run');
        Services::injectMock('occurrenceImportOrchestrator', $orchestrator);

        (new ImportOccurrences(service('logger'), service('commands')))->run([]);

        $this->addToAssertionCount(1);
    }

    /**
     * Verify occurrence command values are clamped and forwarded to its orchestrator.
     */
    public function testOccurrenceCommandClampsArgumentsAndForwardsCheckpoint(): void
    {
        $orchestrator = $this->createMock(ImportOrchestrator::class);
        $orchestrator->expects($this->once())
            ->method('run')
            ->with('nbn', 1, 1, true, 'checkpoint-42')
            ->willReturn([
                'status' => 'success',
                'run_id' => 42,
                'fetched' => 0,
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'checkpoint' => 'checkpoint-42',
            ]);
        Services::injectMock('occurrenceImportOrchestrator', $orchestrator);
        $this->injectSuccessfulLock();

        (new ImportOccurrences(service('logger'), service('commands')))->run([
            'source' => 'nbn',
            'limit' => 0,
            'page-size' => -10,
            'since' => 'checkpoint-42',
            'dry-run' => true,
        ]);

        $this->addToAssertionCount(1);
    }

    /**
     * Verify the automatic command selects and executes the returned task.
     */
    public function testAutoCommandSelectsAndRunsTask(): void
    {
        $service = $this->createMock(AutoImportService::class);
        $service->expects($this->once())
            ->method('select')
            ->willReturn([
                'source_key' => 'nbn-occurrences:occurrences',
                'reason' => 'least recently successful occurrence source',
            ]);
        $service->expects($this->once())
            ->method('run')
            ->with(25, 10, false, [
                'source_key' => 'nbn-occurrences:occurrences',
                'reason' => 'least recently successful occurrence source',
            ])
            ->willReturn([
                'result' => [
                    'status' => 'success',
                    'run_id' => 43,
                ],
            ]);
        Services::injectMock('autoImportService', $service);
        $this->injectSuccessfulLock();

        (new ImportAuto(service('logger'), service('commands')))->run([
            'limit' => 25,
            'page-size' => 10,
        ]);

        $this->addToAssertionCount(1);
    }

    /**
     * Replace the import lock with a mock that always grants the lock.
     */
    private function injectSuccessfulLock(): void
    {
        $lock = $this->createMock(ImportLock::class);
        $lock->expects($this->once())->method('acquire')->willReturn(true);
        $lock->expects($this->once())->method('release');
        Services::injectMock('importLock', $lock);
    }
}
