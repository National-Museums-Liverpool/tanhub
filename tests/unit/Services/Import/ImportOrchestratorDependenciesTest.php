<?php

namespace Tests;

use App\Models\ImportOffsetModel;
use App\Services\Import\ImportOrchestrator;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * @internal
 */
final class ImportOrchestratorDependenciesTest extends CIUnitTestCase
{
    public function testOccurrenceImportIsBlockedUntilAllTaxonomyDependenciesComplete(): void
    {
        $offsetModel = $this->createMock(ImportOffsetModel::class);
        $offsetModel->method('isComplete')->willReturnMap([
            ['indicia-taxonomy:recording_schemes', true],
            ['indicia-taxonomy:geographic_regions', true],
            ['indicia-taxonomy:grid_square_stats', true],
            ['indicia-taxonomy:taxon_groups', true],
            ['indicia-taxonomy:taxon_ranks', true],
            ['indicia-taxonomy:taxa', true],
            ['indicia-taxonomy:taxon_names', false],
        ]);

        $orchestrator = new ImportOrchestrator(
            null,
            null,
            null,
            null,
            null,
            $offsetModel,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot import occurrences until these imports are complete: taxon_names');

        $orchestrator->run('indicia', 500, 100);
    }

    public function testOccurrenceImportForNbnUsesTaxonomyDependencyCompletion(): void
    {
        $offsetModel = $this->createMock(ImportOffsetModel::class);
        $offsetModel->method('isComplete')->willReturnMap([
            ['indicia-taxonomy:recording_schemes', true],
            ['indicia-taxonomy:geographic_regions', true],
            ['indicia-taxonomy:grid_square_stats', false],
            ['indicia-taxonomy:taxon_groups', true],
            ['indicia-taxonomy:taxon_ranks', true],
            ['indicia-taxonomy:taxa', true],
            ['indicia-taxonomy:taxon_names', true],
        ]);

        $orchestrator = new ImportOrchestrator(
            null,
            null,
            null,
            null,
            null,
            $offsetModel,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot import occurrences until these imports are complete: grid_square_stats');

        $orchestrator->run('nbn', 500, 100);
    }
}
