<?php

namespace App\Commands\Support;

use CodeIgniter\CLI\CLI;
use Config\Import as ImportConfig;
use RuntimeException;

/**
 * Runs a CLI import operation while holding the exclusive import lock.
 */
trait UsesImportLock
{
    /**
     * Load validated import settings or show their actionable configuration error.
     *
     * The import config is constructed before command execution, so catch its
     * validation failures here rather than allowing a CLI stack trace to escape.
     *
     * @return ImportConfig|null Validated configuration, or null after an error.
     */
    protected function loadImportConfig(): ?ImportConfig
    {
        try {
            return config(ImportConfig::class);
        } catch (RuntimeException $exception) {
            CLI::error($exception->getMessage());

            return null;
        }
    }

    /**
     * Execute an import operation unless another import holds the lock.
     *
     * @param callable(): void $operation Import operation to execute.
     *
     * @return bool True when the operation ran, or false on lock contention.
     */
    protected function runWithImportLock(callable $operation): bool
    {
        $lock = service('importLock');

        if (! $lock->acquire()) {
            CLI::write('Another import is already running; exiting.', 'yellow');
            return false;
        }

        try {
            $operation();
        } finally {
            $lock->release();
        }

        return true;
    }
}