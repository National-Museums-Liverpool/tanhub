<?php

namespace Tests;

use App\Models\DataSourceModel;
use App\Models\ImportOffsetModel;
use App\Models\ImportRunModel;
use App\Services\Import\Adapter\ImportBatch;
use App\Services\Import\Adapter\ImportSourceAdapterFactory;
use App\Services\Import\Adapter\ImportSourceAdapterInterface;
use App\Services\Import\EntityImportOrchestrator;
use App\Services\Import\Persistence\EntityImportService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Import as ImportConfig;
use RuntimeException;

/**
 * @internal
 */
final class EntityImportOrchestratorRunTest extends CIUnitTestCase
{
    public function testRunDryRunSuccessDoesNotPersistOffsets(): void
    {
        $adapter = $this->createMock(ImportSourceAdapterInterface::class);
        $adapter->expects($this->once())
            ->method('fetchBatch')
            ->with('recording_schemes', 100, 10)
            ->willReturn(new ImportBatch('recording_schemes', 10, [
                ['external_key' => 'one'],
                ['external_key' => 'two'],
            ], 12, false));

        $factory = $this->mockEntityFactory($adapter);

        $entityImportService = $this->createMock(EntityImportService::class);
        $entityImportService->expects($this->once())
            ->method('import')
            ->with('recording_schemes', $this->isType('array'), true)
            ->willReturn([
                'fetched' => 2,
                'processed' => 2,
                'inserted' => 1,
                'updated' => 1,
                'skipped' => 0,
                'errors' => 0,
            ]);

        $importOffsetModel = $this->createMock(ImportOffsetModel::class);
        $importOffsetModel->expects($this->never())->method('setOffset');
        $importOffsetModel->expects($this->never())->method('setCompletion');

        $orchestrator = new EntityImportOrchestrator(
            new ImportConfig(),
            $factory,
            $entityImportService,
            $this->mockImportRunModel(),
            $this->mockDataSourceModel(),
            $importOffsetModel,
        );

        $result = $orchestrator->run('indicia', 'recording_schemes', 100, true, 10);

        $this->assertSame('success', $result['status']);
        $this->assertSame(10, $result['offset']);
        $this->assertSame(12, $result['next_offset']);
        $this->assertFalse($result['has_more']);
    }

    public function testRunNonDrySuccessPersistsOffsetAndCompletion(): void
    {
        $adapter = $this->createMock(ImportSourceAdapterInterface::class);
        $adapter->method('fetchBatch')->willReturn(new ImportBatch('recording_schemes', 0, [
            ['external_key' => 'one'],
        ], 1, false));

        $factory = $this->mockEntityFactory($adapter);

        $entityImportService = $this->createMock(EntityImportService::class);
        $entityImportService->method('import')->willReturn([
            'fetched' => 1,
            'processed' => 1,
            'inserted' => 1,
            'updated' => 0,
            'skipped' => 0,
            'errors' => 0,
        ]);

        $importOffsetModel = $this->createMock(ImportOffsetModel::class);
        $importOffsetModel->expects($this->once())
            ->method('setOffset')
            ->with('indicia-taxonomy:recording_schemes', 1);
        $importOffsetModel->expects($this->once())
            ->method('setCompletion')
            ->with('indicia-taxonomy:recording_schemes', true);

        $orchestrator = new EntityImportOrchestrator(
            new ImportConfig(),
            $factory,
            $entityImportService,
            $this->mockImportRunModel(),
            $this->mockDataSourceModel(),
            $importOffsetModel,
        );

        $result = $orchestrator->run('indicia', 'recording_schemes', 100, false, 0);

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['next_offset']);
        $this->assertFalse($result['has_more']);
    }

    public function testRunWrapsAdapterExceptionsAndMarksCompletionFalse(): void
    {
        $adapter = $this->createMock(ImportSourceAdapterInterface::class);
        $adapter->method('fetchBatch')->willThrowException(new RuntimeException('batch failure'));

        $factory = $this->mockEntityFactory($adapter);

        $importOffsetModel = $this->createMock(ImportOffsetModel::class);
        $importOffsetModel->expects($this->once())
            ->method('setCompletion')
            ->with('indicia-taxonomy:recording_schemes', false);

        $orchestrator = new EntityImportOrchestrator(
            new ImportConfig(),
            $factory,
            $this->createMock(EntityImportService::class),
            $this->mockImportRunModel(),
            $this->mockDataSourceModel(),
            $importOffsetModel,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Import failed: batch failure');

        $orchestrator->run('indicia', 'recording_schemes', 100, false, 0);
    }

    private function mockEntityFactory(ImportSourceAdapterInterface $adapter): ImportSourceAdapterFactory
    {
        $factory = $this->createMock(ImportSourceAdapterFactory::class);
        $factory->method('sourceAbbr')->with('indicia')->willReturn('IREC');
        $factory->method('make')->with('indicia')->willReturn($adapter);

        return $factory;
    }

    private function mockImportRunModel(): ImportRunModel
    {
        $model = $this->createMock(ImportRunModel::class);
        $model->method('insert')->willReturn(77);
        $model->method('update')->willReturn(true);

        return $model;
    }

    private function mockDataSourceModel(): DataSourceModel
    {
        $model = $this->getMockBuilder(DataSourceModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['first'])
            ->addMethods(['where'])
            ->getMock();
        $model->method('where')->with('abbr', 'IREC')->willReturnSelf();
        $model->method('first')->willReturn(['id' => 3, 'abbr' => 'IREC']);

        return $model;
    }
}
