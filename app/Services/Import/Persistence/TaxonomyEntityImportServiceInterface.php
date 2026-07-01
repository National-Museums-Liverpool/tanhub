<?php

namespace App\Services\Import\Persistence;

/**
 * Contract for taxonomy entity persistence services.
 */
interface TaxonomyEntityImportServiceInterface
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    public function import(array $rows, bool $dryRun = false): array;
}
