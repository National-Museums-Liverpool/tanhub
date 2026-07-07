<?php

namespace App\Services\Import\Persistence;

/**
 * Contract for import entity persistence services.
 */
interface EntityImportServiceInterface
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, int>
     */
    public function import(array $rows, bool $dryRun = false): array;
}