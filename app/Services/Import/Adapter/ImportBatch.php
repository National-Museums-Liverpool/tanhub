<?php

namespace App\Services\Import\Adapter;

/**
 * Represents one normalized import batch.
 */
class ImportBatch
{
    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function __construct(
        public string $entity,
        public int $offset,
        public array $rows,
        public int $nextOffset,
        public bool $hasMore,
    ) {
    }
}