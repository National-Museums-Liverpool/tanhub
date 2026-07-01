<?php

namespace App\Services\Import\Adapter;

/**
 * Contract for external occurrence source adapters.
 */
interface OccurrenceSourceAdapterInterface
{
    /**
     * Fetch one normalized page of occurrence records.
     */
    public function fetchPage(?string $checkpoint, int $limit): ImportPage;
}
