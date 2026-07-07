<?php

namespace App\Services\Import\Persistence;

use InvalidArgumentException;

/**
 * Routes import persistence to entity-specific import services.
 */
class EntityImportService
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    public function import(string $entity, array $rows, bool $dryRun = false): array
    {
        return $this->serviceFor($entity)->import($rows, $dryRun);
    }

    private function serviceFor(string $entity): EntityImportServiceInterface
    {
        return match (strtolower($entity)) {
            'taxon_groups' => new TaxonGroupsImportService(),
            'recording_schemes' => new RecordingSchemesImportService(),
            'taxon_ranks' => new TaxonRanksImportService(),
            'geographic_regions' => new GeographicRegionsImportService(),
            'taxa' => new TaxaImportService(),
            default => throw new InvalidArgumentException('Unsupported import entity: ' . $entity),
        };
    }
}