<?php

namespace App\Commands\Support;

use CodeIgniter\CLI\CLI;

/**
 * Runs a CLI import operation while holding the exclusive import lock.
 */
trait UsesImportLock
{
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