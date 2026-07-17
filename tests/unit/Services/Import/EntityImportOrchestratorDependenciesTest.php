<?php

namespace Tests;

use App\Models\ImportOffsetModel;
use App\Services\Import\EntityImportOrchestrator;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * @internal
 */
final class EntityImportOrchestratorDependenciesTest extends CIUnitTestCase
{
    public function testTaxaImportIsBlockedWhenDependenciesAreIncomplete(): void
    {
        $offsetModel = $this->createMock(ImportOffsetModel::class);
        $offsetModel->method('isComplete')->willReturnMap([
            ['indicia-taxonomy:recording_schemes', true],
            ['indicia-taxonomy:geographic_regions', true],
            ['indicia-taxonomy:taxon_groups', false],
            ['indicia-taxonomy:taxon_ranks', true],
        ]);

        $orchestrator = new EntityImportOrchestrator(
            null,
            null,
            null,
            null,
            null,
            $offsetModel,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot import taxa until these imports are complete: taxon_groups');

        $orchestrator->run('indicia', 'taxa', 1000);
    }

    public function testTaxonNamesImportIsBlockedUntilTaxaComplete(): void
    {
        $offsetModel = $this->createMock(ImportOffsetModel::class);
        $offsetModel->method('isComplete')->willReturnMap([
            ['indicia-taxonomy:taxa', false],
        ]);

        $orchestrator = new EntityImportOrchestrator(
            null,
            null,
            null,
            null,
            null,
            $offsetModel,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot import taxon_names until these imports are complete: taxa');

        $orchestrator->run('indicia', 'taxon_names', 1000);
    }

    public function testGridSquareStatsImportIsBlockedUntilGeographicRegionsComplete(): void
    {
        $offsetModel = $this->createMock(ImportOffsetModel::class);
        $offsetModel->method('isComplete')->willReturnMap([
            ['indicia-taxonomy:geographic_regions', false],
        ]);

        $orchestrator = new EntityImportOrchestrator(
            null,
            null,
            null,
            null,
            null,
            $offsetModel,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot import grid_square_stats until these imports are complete: geographic_regions');

        $orchestrator->run('indicia', 'grid_square_stats', 1000);
    }
}
