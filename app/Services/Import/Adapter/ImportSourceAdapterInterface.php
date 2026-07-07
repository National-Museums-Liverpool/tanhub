<?php

namespace App\Services\Import\Adapter;

/**
 * Contract for external import source adapters.
 */
interface ImportSourceAdapterInterface
{
    /**
     * Fetch one normalized import batch.
     */
    public function fetchBatch(string $entity, int $limit, int $offset): ImportBatch;
}