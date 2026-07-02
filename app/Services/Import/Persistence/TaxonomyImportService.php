<?php

namespace App\Services\Import\Persistence;

use InvalidArgumentException;

/**
 * Routes taxonomy persistence to entity-specific import services.
 */
class TaxonomyImportService
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    public function import(string $entity, array $rows, bool $dryRun = false): array
    {
        return $this->serviceFor($entity)->import($rows, $dryRun);
    }

    private function serviceFor(string $entity): TaxonomyEntityImportServiceInterface
    {
        return match (strtolower($entity)) {
            'taxon_groups' => new TaxonGroupsImportService(),
            'recording_schemes' => new RecordingSchemesImportService(),
            'taxon_ranks' => new TaxonRanksImportService(),
            'taxa' => new TaxaImportService(),
            default => throw new InvalidArgumentException('Unsupported taxonomy entity: ' . $entity),
        };
    }
}
