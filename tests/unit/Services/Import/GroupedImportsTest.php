<?php

namespace Tests;

use App\Models\DataSourceModel;
use App\Models\ImportOffsetModel;
use App\Models\ImportRunModel;
use App\Services\Import\Adapter\ImportBatch;
use App\Services\Import\Adapter\ImportSourceAdapterFactory;
use App\Services\Import\Adapter\ImportSourceAdapterInterface;
use App\Services\Import\Adapter\ImportPage;
use App\Services\Import\Adapter\OccurrenceSourceAdapterFactory;
use App\Services\Import\Adapter\OccurrenceSourceAdapterInterface;
use App\Services\Import\EntityImportOrchestrator;
use App\Services\Import\GeographicImportProgress;
use App\Services\Import\ImportOrchestrator;
use App\Services\Import\Persistence\EntityImportService;
use App\Services\Import\Persistence\GeographicRegionsOccurrenceImportService;
use App\Services\Import\Persistence\OccurrenceImportService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Import;
use InvalidArgumentException;

/**
 * Exercise independent geography streams behind the existing task facade.
 *
 * @internal
 */
final class GroupedImportsTest extends CIUnitTestCase
{
    /**
     * Model progress in memory while retaining real orchestrator behavior.
     *
     * @var array<string, array{offset?: int, checkpoint?: string|null, complete?: bool}>
     */
    private array $state = [];

    /**
     * A bounded entity run finishes only its selected group and resumes the next.
     */
    public function testLookupGroupsHaveIndependentOffsetsAndParentCompletion(): void
    {
        $offsets = $this->offsets();
        $factory = $this->createMock(ImportSourceAdapterFactory::class);
        $factory->method('sourceAbbr')->willReturn('IREC');
        $seen = [];
        $factory->method('make')->willReturnCallback(function (string $source, ?string $group) use (&$seen): ImportSourceAdapterInterface {
            $seen[] = $group;
            $adapter = $this->createMock(ImportSourceAdapterInterface::class);
            $adapter->expects($this->once())->method('fetchBatch')->with('geographic_regions', 2, 0)
                ->willReturn(new ImportBatch('geographic_regions', 0, [], 0, false));

            return $adapter;
        });
        $persistence = $this->createMock(EntityImportService::class);
        $persistence->method('import')->willReturn($this->counts());
        $runner = new EntityImportOrchestrator(
            $this->config(), $factory, $persistence, $this->runs(), $this->source(), $offsets,
        );

        $first = $runner->run('indicia', 'geographic_regions', 2);
        $this->assertTrue($first['has_more']);
        $this->assertFalse($offsets->isComplete('indicia-taxonomy:geographic_regions'));
        $this->assertTrue($offsets->isComplete('indicia-taxonomy:geographic_regions:vice-counties'));
        $second = $runner->run('indicia', 'geographic_regions', 2);
        $this->assertFalse($second['has_more']);
        $this->assertTrue($offsets->isComplete('indicia-taxonomy:geographic_regions'));
        $this->assertSame(['vice-counties', 'lrc'], $seen);
    }

    /**
     * Exact-page responses continue the same group and never leak offsets to the next.
     */
    public function testProgressResumesUnfinishedGroupsThenRotatesCompletedGroups(): void
    {
        $offsets = $this->offsets();
        $progress = new GeographicImportProgress($offsets, 'task', ['vc', 'lrc']);
        $this->assertSame('vc', $progress->select());
        $offsets->setOffset($progress->key('vc'), 5000);
        $this->assertFalse($progress->finish('vc', false));
        $this->assertSame('vc', $progress->select());
        $this->assertSame(5000, $offsets->getOffset($progress->key('vc')));
        $this->assertFalse($progress->finish('vc', true));
        $this->assertSame('lrc', $progress->select());
        $this->assertSame(0, $offsets->getOffset($progress->key('lrc')));
        $this->assertTrue($progress->finish('lrc', true));
        $this->assertSame('vc', $progress->select());
        $this->assertTrue($progress->finish('vc', true));
        $this->assertSame('lrc', $progress->select());
    }

    /**
     * An independent NBN stream starts at zero, not the other group's high cursor.
     */
    public function testNbnGroupsHaveIndependentCursorsAndRecurringRotation(): void
    {
        $offsets = $this->offsets();
        $offsets->setCompletion('indicia-taxonomy:geographic_regions', true);
        $parent = 'nbn-occurrences:occurrences';
        $offsets->setCheckpoint($parent . ':vice-counties', 'occurrenceID:900');
        $factory = $this->createMock(OccurrenceSourceAdapterFactory::class);
        $factory->method('sourceAbbr')->willReturn('NBN');
        $seen = [];
        $factory->method('make')->willReturnCallback(function (string $source, ?string $group) use (&$seen): OccurrenceSourceAdapterInterface {
            $seen[] = $group;
            $adapter = $this->createMock(OccurrenceSourceAdapterInterface::class);
            $adapter->expects($this->once())->method('fetchPage')
                ->with($group === 'lrc' ? '0' : 'occurrenceID:900', 10)
                ->willReturn(new ImportPage([], $group === 'lrc' ? '0' : 'occurrenceID:900', false));

            return $adapter;
        });
        $persistence = $this->createMock(OccurrenceImportService::class);
        $persistence->method('import')->willReturn($this->counts());
        $assignments = $this->createMock(GeographicRegionsOccurrenceImportService::class);
        $assignments->method('run')->willReturn(['errors' => 0]);
        $runner = new ImportOrchestrator(
            $this->config(), $factory, $persistence, $this->runs(), $this->source(), $offsets, $assignments,
        );

        $runner->run('nbn', 10, 10);
        $this->assertFalse($offsets->isComplete($parent));
        $runner->run('nbn', 10, 10);
        $this->assertTrue($offsets->isComplete($parent));
        $runner->run('nbn', 10, 10);
        $this->assertSame(['vice-counties', 'lrc', 'vice-counties'], $seen);
    }

    /**
     * A dry run does not publish selected-group or parent progress.
     */
    public function testGroupedDryRunDoesNotWriteProgress(): void
    {
        $offsets = $this->offsets();
        $before = $this->state;
        $adapter = $this->createMock(ImportSourceAdapterInterface::class);
        $adapter->method('fetchBatch')->willReturn(new ImportBatch('geographic_regions', 0, [], 0, false));
        $factory = $this->createMock(ImportSourceAdapterFactory::class);
        $factory->method('sourceAbbr')->willReturn('IREC');
        $factory->method('make')->willReturn($adapter);
        $persistence = $this->createMock(EntityImportService::class);
        $persistence->method('import')->willReturn($this->counts());
        $runner = new EntityImportOrchestrator(
            $this->config(), $factory, $persistence, $this->runs(), $this->source(), $offsets,
        );
        $result = $runner->run('indicia', 'geographic_regions', 10, true);

        $this->assertSame($before, $this->state);
        $this->assertTrue($result['has_more']);
    }

    /**
     * A single cursor override must not ambiguously target several NBN streams.
     */
    public function testMultipleGroupsRejectCheckpointOverride(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ambiguous');
        (new ImportOrchestrator(config: $this->config()))->run('nbn', 10, 10, true, '0');
    }

    /**
     * Failed persistence advances only successful rows of the selected lookup group.
     */
    public function testLookupFailurePreservesOtherGroupProgress(): void
    {
        $offsets = $this->offsets();
        $parent = 'indicia-taxonomy:geographic_regions';
        $offsets->setOffset($parent . ':lrc', 40);
        $adapter = $this->createMock(ImportSourceAdapterInterface::class);
        $adapter->method('fetchBatch')->willReturn(new ImportBatch(
            'geographic_regions', 0, [['id' => 1], ['id' => 2]], 2, false,
        ));
        $factory = $this->createMock(ImportSourceAdapterFactory::class);
        $factory->method('sourceAbbr')->willReturn('IREC');
        $factory->method('make')->willReturn($adapter);
        $persistence = $this->createMock(EntityImportService::class);
        $persistence->method('import')->willReturn([
            'fetched' => 2, 'processed' => 1, 'inserted' => 1, 'updated' => 0, 'skipped' => 0, 'errors' => 1,
        ]);
        $runner = new EntityImportOrchestrator(
            $this->config(), $factory, $persistence, $this->runs(), $this->source(), $offsets,
        );
        $result = $runner->run('indicia', 'geographic_regions', 2);

        $this->assertSame('failed', $result['status']);
        $this->assertTrue($result['has_more']);
        $this->assertSame(1, $offsets->getOffset($parent . ':vice-counties'));
        $this->assertSame(40, $offsets->getOffset($parent . ':lrc'));
        $this->assertFalse($offsets->isComplete($parent));
        $this->assertSame('vice-counties', (new GeographicImportProgress($offsets, $parent, ['vice-counties', 'lrc']))->select());
    }

    /**
     * An NBN request failure retains that stream's cursor and leaves the other untouched.
     */
    public function testNbnFailureDoesNotAdvanceAnotherStream(): void
    {
        $offsets = $this->offsets();
        $offsets->setCompletion('indicia-taxonomy:geographic_regions', true);
        $parent = 'nbn-occurrences:occurrences';
        $offsets->setCheckpoint($parent . ':vice-counties', 'occurrenceID:100');
        $offsets->setCheckpoint($parent . ':lrc', 'occurrenceID:20');
        $adapter = $this->createMock(OccurrenceSourceAdapterInterface::class);
        $adapter->method('fetchPage')->willThrowException(new \RuntimeException('NBN unavailable'));
        $factory = $this->createMock(OccurrenceSourceAdapterFactory::class);
        $factory->method('sourceAbbr')->willReturn('NBN');
        $factory->method('make')->willReturn($adapter);
        $runner = new ImportOrchestrator(
            $this->config(), $factory, $this->createMock(OccurrenceImportService::class),
            $this->runs(), $this->source(), $offsets,
            $this->createMock(GeographicRegionsOccurrenceImportService::class),
        );
        try {
            $runner->run('nbn', 10, 10);
            $this->fail('Expected request failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('NBN unavailable', $exception->getMessage());
        }
        $this->assertSame('occurrenceID:100', $offsets->getCheckpoint($parent . ':vice-counties'));
        $this->assertSame('occurrenceID:20', $offsets->getCheckpoint($parent . ':lrc'));
        $this->assertFalse($offsets->isComplete($parent));
    }

    /**
     * Clearing offsets starts at zero even when old run history is retained.
     */
    public function testClearedSingleGroupOffsetsDoNotConsultRunHistory(): void
    {
        $offsets = $this->offsets();
        $offsets->setCompletion('indicia-taxonomy:geographic_regions', true);
        $adapter = $this->createMock(OccurrenceSourceAdapterInterface::class);
        $adapter->expects($this->once())->method('fetchPage')->with('0', 10)
            ->willReturn(new ImportPage([], '0', false));
        $factory = $this->createMock(OccurrenceSourceAdapterFactory::class);
        $factory->method('sourceAbbr')->willReturn('NBN');
        $factory->method('make')->willReturn($adapter);
        $persistence = $this->createMock(OccurrenceImportService::class);
        $persistence->method('import')->willReturn($this->counts());
        $runner = new ImportOrchestrator(
            new Import(), $factory, $persistence, $this->runs(), $this->source(), $offsets,
        );

        $this->assertSame('0', $runner->run('nbn', 10, 10, true)['checkpoint']);
    }

    /**
     * Supply installation configuration with both geography types.
     *
     * @return Import Two-group configuration.
     */
    private function config(): Import
    {
        $config = new Import();
        $config->geographicRegions['lrc'] = [
            'indiciaLocationType' => 'LRC Boundary',
            'nbnField' => 'cl1012',
            'locations' => [['irecordName' => 'Lancashire Environmental Records Network']],
        ];

        return $config;
    }

    /**
     * Create progress storage with completed taxonomy prerequisites.
     *
     * @return ImportOffsetModel Stateful mocked progress model.
     */
    private function offsets(): ImportOffsetModel
    {
        foreach (['recording_schemes', 'geographic_regions', 'grid_square_stats', 'taxon_groups', 'taxon_ranks', 'taxa', 'taxon_names'] as $entity) {
            $this->state['indicia-taxonomy:' . $entity]['complete'] = true;
        }
        $this->state['indicia-taxonomy:geographic_regions']['complete'] = false;
        $model = $this->createMock(ImportOffsetModel::class);
        $model->method('isComplete')->willReturnCallback(fn (string $key): bool => $this->state[$key]['complete'] ?? false);
        $model->method('getOffset')->willReturnCallback(fn (string $key): int => $this->state[$key]['offset'] ?? 0);
        $model->method('getCheckpoint')->willReturnCallback(fn (string $key): ?string => $this->state[$key]['checkpoint'] ?? null);
        $model->method('setOffset')->willReturnCallback(function (string $key, int $offset): void {
            $this->state[$key]['offset'] = $offset;
        });
        $model->method('setCheckpoint')->willReturnCallback(function (string $key, ?string $checkpoint): void {
            $this->state[$key]['checkpoint'] = $checkpoint;
        });
        $model->method('setCompletion')->willReturnCallback(function (string $key, bool $complete): void {
            $this->state[$key]['complete'] = $complete;
        });

        return $model;
    }

    /**
     * Supply successful empty-page persistence counts.
     *
     * @return array<string, int> Empty batch counts.
     */
    private function counts(): array
    {
        return ['fetched' => 0, 'processed' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
    }

    /**
     * Keep run history separate from cursor storage.
     *
     * @return ImportRunModel Run-history fake that must never supply fallback cursors.
     */
    private function runs(): ImportRunModel
    {
        $model = $this->createMock(ImportRunModel::class);
        $model->method('insert')->willReturn(77);
        $model->method('update')->willReturn(true);
        $model->expects($this->never())->method('first');

        return $model;
    }

    /**
     * Resolve the source without querying a real database.
     *
     * @return DataSourceModel Source model.
     */
    private function source(): DataSourceModel
    {
        $model = $this->getMockBuilder(DataSourceModel::class)->disableOriginalConstructor()
            ->onlyMethods(['first'])->addMethods(['where'])->getMock();
        $model->method('where')->willReturnSelf();
        $model->method('first')->willReturn(['id' => 1]);

        return $model;
    }
}
