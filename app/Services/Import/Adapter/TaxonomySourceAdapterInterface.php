<?php

namespace App\Services\Import\Adapter;

/**
 * Contract for external taxonomy source adapters.
 */
interface TaxonomySourceAdapterInterface
{
    /**
     * Fetch one normalized taxonomy batch.
     */
    public function fetchBatch(string $entity, int $limit, int $offset): TaxonomyImportBatch;
}
