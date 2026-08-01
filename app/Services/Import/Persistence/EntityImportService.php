<?php

namespace App\Services\Import\Persistence;

use InvalidArgumentException;

/**
 * Routes import persistence to entity-specific import services.
 *
 * Acts as a simple factory/dispatcher so orchestrators can persist rows for
 * any supported entity without knowing which concrete
 * {@see EntityImportServiceInterface} implementation handles it.
 */
class EntityImportService
{
    /**
     * Persist a batch of normalized rows for the named entity.
     *
     * @param string                            $entity Entity name (case-insensitive),
     *                                                   e.g. `taxa`, `taxon_groups`,
     *                                                   `recording_schemes`.
     * @param array<int, array<string, mixed>>  $rows   Normalized source rows to upsert.
     * @param bool                              $dryRun When true, compute counts without
     *                                                   writing changes.
     *
     * @return array<string, int> Result counts (`fetched`, `processed`, `inserted`,
     *                            `updated`, `skipped`, `errors`) from the delegate service.
     *
     * @throws InvalidArgumentException When `$entity` has no registered import service.
     */
    public function import(string $entity, array $rows, bool $dryRun = false): array
    {
        return $this->serviceFor($entity)->import($rows, $dryRun);
    }

    /**
     * Resolve the persistence service responsible for the named entity.
     *
     * @param string $entity Entity name (case-insensitive).
     *
     * @return EntityImportServiceInterface Service instance for the requested entity.
     *
     * @throws InvalidArgumentException When `$entity` does not match a known entity.
     */
    private function serviceFor(string $entity): EntityImportServiceInterface
    {
        return match (strtolower($entity)) {
            'taxon_groups' => new TaxonGroupsImportService(),
            'recording_schemes' => new RecordingSchemesImportService(),
            'taxon_ranks' => new TaxonRanksImportService(),
            'geographic_regions' => new GeographicRegionsImportService(),
            'grid_square_stats' => new GridSquareStatsImportService(),
            'taxa' => new TaxaImportService(),
            'taxon_names' => new TaxonNamesImportService(),
            default => throw new InvalidArgumentException('Unsupported import entity: ' . $entity),
        };
    }
}