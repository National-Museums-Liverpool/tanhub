<?php

namespace Tests;

use App\Models\DataSourceModel;
use App\Models\ImportOffsetModel;
use App\Models\ImportRunModel;
use App\Services\Import\Adapter\ImportPage;
use App\Services\Import\Adapter\OccurrenceSourceAdapterFactory;
use App\Services\Import\Adapter\OccurrenceSourceAdapterInterface;
use App\Services\Import\ImportOrchestrator;
use App\Services\Import\Persistence\GeographicRegionsOccurrenceImportService;
use App\Services\Import\Persistence\OccurrenceImportService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Import as ImportConfig;
use RuntimeException;

/**
 * @internal
 */
final class ImportOrchestratorRunTest extends CIUnitTestCase
{
    /**
     * Verify that dry-run imports return counts without persisting offsets.
     *
     * @return void
     */
    public function testRunDryRunSuccessReturnsCountsAndSkipsOffsetPersistence(): void
    {
        $adapter = $this->createMock(OccurrenceSourceAdapterInterface::class);
        $adapter->expects($this->once())
            ->method('fetchPage')
            ->with('override-cp', 50)
            ->willReturn(new ImportPage([
                ['remote_id' => 'one'],
                ['remote_id' => 'two'],
            ], 'next-cp', false));

        $adapterFactory = $this->mockAdapterFactory($adapter);
        $occurrenceImportService = $this->createMock(OccurrenceImportService::class);
        $occurrenceImportService->expects($this->once())
            ->method('import')
            ->with($this->isType('array'), 5, 'NBN', true)
            ->willReturn([
                'fetched' => 2,
                'processed' => 2,
                'inserted' => 1,
                'updated' => 0,
                'skipped' => 1,
                'errors' => 0,
                'last_checkpoint' => 'record-cp',
            ]);

        $importRunModel = $this->mockImportRunModel();
        $importOffsetModel = $this->mockImportOffsetModel(true);
        $importOffsetModel->expects($this->never())->method('setCheckpoint');
        $importOffsetModel->expects($this->never())->method('setCompletion');

        $orchestrator = new ImportOrchestrator(
            new ImportConfig(),
            $adapterFactory,
            $occurrenceImportService,
            $importRunModel,
            $this->mockDataSourceModel(),
            $importOffsetModel,
        );

        $result = $orchestrator->run('nbn', 50, 100, true, 'override-cp');

        $this->assertSame('success', $result['status']);
        $this->assertSame('record-cp', $result['checkpoint']);
        $this->assertSame(2, $result['fetched']);
        $this->assertSame(1, $result['inserted']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['errors']);
    }

    /**
     * Verify that a row failure marks the import incomplete.
     *
     * @return void
     */
    public function testRunNonDryFailedImportSetsCompletionFalse(): void
    {
        $adapter = $this->createMock(OccurrenceSourceAdapterInterface::class);
        $adapter->method('fetchPage')->willReturn(new ImportPage([
            ['remote_id' => 'one'],
        ], 'page-cp', true));

        $adapterFactory = $this->mockAdapterFactory($adapter);
        $occurrenceImportService = $this->createMock(OccurrenceImportService::class);
        $occurrenceImportService->method('import')->willReturn([
            'fetched' => 1,
            'processed' => 1,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 1,
            'last_checkpoint' => 'error-cp',
        ]);

        $importRunModel = $this->mockImportRunModel();
        $importOffsetModel = $this->mockImportOffsetModel(true);
        $importOffsetModel->expects($this->once())
            ->method('setCheckpoint')
            ->with('nbn-occurrences:occurrences', 'error-cp');
        $importOffsetModel->expects($this->once())
            ->method('setCompletion')
            ->with('nbn-occurrences:occurrences', false);

        $orchestrator = new ImportOrchestrator(
            new ImportConfig(),
            $adapterFactory,
            $occurrenceImportService,
            $importRunModel,
            $this->mockDataSourceModel(),
            $importOffsetModel,
        );

        $result = $orchestrator->run('nbn', 10, 10, false, 'start-cp');

        $this->assertSame('failed', $result['status']);
        $this->assertSame('error-cp', $result['checkpoint']);
        $this->assertSame(1, $result['errors']);
    }

    /**
     * Verify that geographic reassignment runs once for each persisted page.
     *
     * @return void
     */
    public function testRunReassignsGeographicRegionsAfterEachPersistedPage(): void
    {
        $adapter = $this->createMock(OccurrenceSourceAdapterInterface::class);
        $adapter->expects($this->exactly(2))
            ->method('fetchPage')
            ->willReturnOnConsecutiveCalls(
                new ImportPage([['remote_id' => 'one']], 'page-one', true),
                new ImportPage([['remote_id' => 'two']], 'page-two', false),
            );

        $occurrenceImportService = $this->createMock(OccurrenceImportService::class);
        $occurrenceImportService->expects($this->exactly(2))
            ->method('import')
            ->willReturnOnConsecutiveCalls(
                [
                    'fetched' => 1,
                    'processed' => 1,
                    'inserted' => 1,
                    'updated' => 0,
                    'skipped' => 0,
                    'errors' => 0,
                    'last_checkpoint' => 'page-one',
                    'changed_occurrence_ids' => [101],
                ],
                [
                    'fetched' => 1,
                    'processed' => 1,
                    'inserted' => 0,
                    'updated' => 1,
                    'skipped' => 0,
                    'errors' => 0,
                    'last_checkpoint' => 'page-two',
                    'changed_occurrence_ids' => [102],
                ],
            );

        $geographicRegionsOccurrenceImportService = $this->createMock(GeographicRegionsOccurrenceImportService::class);
        $assignmentCalls = [];
        $geographicRegionsOccurrenceImportService->expects($this->exactly(2))
            ->method('run')
            ->willReturnCallback(function (bool $dryRun, ?array $occurrenceIds) use (&$assignmentCalls): array {
                $assignmentCalls[] = [$dryRun, $occurrenceIds];

                return ['status' => 'success', 'errors' => 0];
            });

        $importRunModel = $this->mockImportRunModel();
        $importOffsetModel = $this->mockImportOffsetModel(true);
        $importOffsetModel->expects($this->once())
            ->method('setCheckpoint')
            ->with('nbn-occurrences:occurrences', 'page-two');
        $importOffsetModel->expects($this->once())
            ->method('setCompletion')
            ->with('nbn-occurrences:occurrences', true);

        $orchestrator = new ImportOrchestrator(
            new ImportConfig(),
            $this->mockAdapterFactory($adapter),
            $occurrenceImportService,
            $importRunModel,
            $this->mockDataSourceModel(),
            $importOffsetModel,
            $geographicRegionsOccurrenceImportService,
        );

        $result = $orchestrator->run('nbn', 10, 1, false, 'start-cp');

        $this->assertSame('success', $result['status']);
        $this->assertSame('page-two', $result['checkpoint']);
        $this->assertSame([[false, [101]], [false, [102]]], $assignmentCalls);
    }

    /**
     * Verify that adapter exceptions are wrapped and the offset is incomplete.
     *
     * @return void
     */
    public function testRunWrapsAdapterExceptionsAndMarksOffsetIncomplete(): void
    {
        $adapter = $this->createMock(OccurrenceSourceAdapterInterface::class);
        $adapter->method('fetchPage')->willThrowException(new RuntimeException('boom'));

        $adapterFactory = $this->mockAdapterFactory($adapter);
        $importRunModel = $this->mockImportRunModel();
        $importOffsetModel = $this->mockImportOffsetModel(true);
        $importOffsetModel->expects($this->once())
            ->method('setCheckpoint')
            ->with('nbn-occurrences:occurrences', 'start-cp');
        $importOffsetModel->expects($this->once())
            ->method('setCompletion')
            ->with('nbn-occurrences:occurrences', false);

        $orchestrator = new ImportOrchestrator(
            new ImportConfig(),
            $adapterFactory,
            $this->createMock(OccurrenceImportService::class),
            $importRunModel,
            $this->mockDataSourceModel(),
            $importOffsetModel,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Import failed: boom');

        $orchestrator->run('nbn', 10, 10, false, 'start-cp');
    }

    /**
     * Verify that a new NBN import starts at checkpoint zero.
     *
     * @return void
     */
    public function testRunForNbnStartsFromZeroWhenNoCheckpointOverrideProvided(): void
    {
        $adapter = $this->createMock(OccurrenceSourceAdapterInterface::class);
        $adapter->expects($this->once())
            ->method('fetchPage')
            ->with('0', 10)
            ->willReturn(new ImportPage([], '0', false));

        $adapterFactory = $this->mockAdapterFactory($adapter);
        $occurrenceImportService = $this->createMock(OccurrenceImportService::class);
        $occurrenceImportService->expects($this->once())
            ->method('import')
            ->willReturn([
                'fetched' => 0,
                'processed' => 0,
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'last_checkpoint' => null,
            ]);

        $importRunModel = $this->mockImportRunModel();
        $importOffsetModel = $this->mockImportOffsetModel(true);
        $importOffsetModel->expects($this->never())->method('setCheckpoint');
        $importOffsetModel->expects($this->never())->method('setCompletion');

        $orchestrator = new ImportOrchestrator(
            new ImportConfig(),
            $adapterFactory,
            $occurrenceImportService,
            $importRunModel,
            $this->mockDataSourceModel(),
            $importOffsetModel,
        );

        $result = $orchestrator->run('nbn', 10, 10, true);

        $this->assertSame('success', $result['status']);
    }

    /**
     * Verify that an NBN import resumes from its stored checkpoint.
     *
     * @return void
     */
    public function testRunForNbnResumesFromStoredCheckpoint(): void
    {
        $adapter = $this->createMock(OccurrenceSourceAdapterInterface::class);
        $adapter->expects($this->once())
            ->method('fetchPage')
            ->with('25', 10)
            ->willReturn(new ImportPage([
                ['remote_id' => 'one'],
            ], '25', true));

        $adapterFactory = $this->mockAdapterFactory($adapter);
        $occurrenceImportService = $this->createMock(OccurrenceImportService::class);
        $occurrenceImportService->expects($this->once())
            ->method('import')
            ->willReturn([
                'fetched' => 1,
                'processed' => 1,
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'errors' => 1,
                'last_checkpoint' => '25',
            ]);

        $importRunModel = $this->mockImportRunModel();
        $importOffsetModel = $this->mockImportOffsetModel(true, '25');
        $importOffsetModel->expects($this->once())
            ->method('setCheckpoint')
            ->with('nbn-occurrences:occurrences', '25');
        $importOffsetModel->expects($this->once())
            ->method('setCompletion')
            ->with('nbn-occurrences:occurrences', false);

        $orchestrator = new ImportOrchestrator(
            new ImportConfig(),
            $adapterFactory,
            $occurrenceImportService,
            $importRunModel,
            $this->mockDataSourceModel(),
            $importOffsetModel,
        );

        $result = $orchestrator->run('nbn', 10, 10);

        $this->assertSame('failed', $result['status']);
        $this->assertSame('25', $result['checkpoint']);
    }

    /**
     * Build an adapter factory returning the supplied adapter.
     *
     * @param OccurrenceSourceAdapterInterface $adapter Adapter mock to return.
     * @return OccurrenceSourceAdapterFactory Configured adapter factory mock.
     */
    private function mockAdapterFactory(OccurrenceSourceAdapterInterface $adapter): OccurrenceSourceAdapterFactory
    {
        $adapterFactory = $this->createMock(OccurrenceSourceAdapterFactory::class);
        $adapterFactory->method('sourceAbbr')->with('nbn')->willReturn('NBN');
        $adapterFactory->method('make')->with('nbn')->willReturn($adapter);

        return $adapterFactory;
    }

    /**
     * Build a mock import-run model with fluent query methods.
     *
     * @return ImportRunModel Import-run model mock.
     */
    private function mockImportRunModel(): ImportRunModel
    {
        $importRunModel = $this->getMockBuilder(ImportRunModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['insert', 'update', 'first'])
            ->addMethods(['where', 'whereIn', 'orderBy'])
            ->getMock();
        $importRunModel->method('insert')->willReturn(99);
        $importRunModel->method('update')->willReturn(true);
        $importRunModel->method('where')->willReturnSelf();
        $importRunModel->method('whereIn')->willReturnSelf();
        $importRunModel->method('orderBy')->willReturnSelf();
        $importRunModel->method('first')->willReturn(null);

        return $importRunModel;
    }

    /**
     * Build a data-source model returning the NBN data source.
     *
     * @return DataSourceModel Data-source model mock.
     */
    private function mockDataSourceModel(): DataSourceModel
    {
        $dataSourceModel = $this->getMockBuilder(DataSourceModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['first'])
            ->addMethods(['where'])
            ->getMock();
        $dataSourceModel->method('where')->with('abbr', 'NBN')->willReturnSelf();
        $dataSourceModel->method('first')->willReturn(['id' => 5, 'abbr' => 'NBN']);

        return $dataSourceModel;
    }

    /**
     * Build an import-offset model with configured checkpoint state.
     *
     * @param bool        $complete   Whether the dependency state is complete.
     * @param string|null $checkpoint Stored checkpoint, when available.
     * @return ImportOffsetModel Import-offset model mock.
     */
    private function mockImportOffsetModel(bool $complete, ?string $checkpoint = null): ImportOffsetModel
    {
        $importOffsetModel = $this->createMock(ImportOffsetModel::class);
        $importOffsetModel->method('isComplete')->willReturn($complete);
        $importOffsetModel->method('getCheckpoint')->willReturn($checkpoint);

        return $importOffsetModel;
    }
}
