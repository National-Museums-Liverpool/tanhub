<?php

namespace Tests;

use App\Models\DataSourceModel;
use App\Models\ImportOffsetModel;
use App\Models\ImportRunModel;
use App\Services\Import\Adapter\ImportPage;
use App\Services\Import\Adapter\OccurrenceSourceAdapterFactory;
use App\Services\Import\Adapter\OccurrenceSourceAdapterInterface;
use App\Services\Import\ImportOrchestrator;
use App\Services\Import\Persistence\OccurrenceImportService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Import as ImportConfig;
use RuntimeException;

/**
 * @internal
 */
final class ImportOrchestratorRunTest extends CIUnitTestCase
{
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

    private function mockAdapterFactory(OccurrenceSourceAdapterInterface $adapter): OccurrenceSourceAdapterFactory
    {
        $adapterFactory = $this->createMock(OccurrenceSourceAdapterFactory::class);
        $adapterFactory->method('sourceAbbr')->with('nbn')->willReturn('NBN');
        $adapterFactory->method('make')->with('nbn')->willReturn($adapter);

        return $adapterFactory;
    }

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

    private function mockImportOffsetModel(bool $complete, ?string $checkpoint = null): ImportOffsetModel
    {
        $importOffsetModel = $this->createMock(ImportOffsetModel::class);
        $importOffsetModel->method('isComplete')->willReturn($complete);
        $importOffsetModel->method('getCheckpoint')->willReturn($checkpoint);

        return $importOffsetModel;
    }
}
