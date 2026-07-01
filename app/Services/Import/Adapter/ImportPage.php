<?php

namespace App\Services\Import\Adapter;

/**
 * Represents a single fetched page of normalized source records.
 */
class ImportPage
{
    /**
     * @param array<int, array<string, mixed>> $records
     */
    public function __construct(
        public array $records,
        public ?string $nextCheckpoint,
        public bool $hasMore,
    ) {
    }
}
