<?php

namespace App\Services\Import;

use Config\Import as ImportConfig;
use RuntimeException;
use Throwable;

/**
 * Provides a process-independent database lease for automated imports.
 */
class AutoImportLock
{
    /**
     * @var object|null Dedicated database connection holding the advisory lock.
     */
    private ?object $connection = null;

    /**
     * Acquire the automatic import advisory lock without waiting.
     *
     * @return bool True when this process acquired the lock.
     *
     * @throws RuntimeException When the configured database does not support advisory locks.
     */
    public function acquire(): bool
    {
        $connection = db_connect('default', false);
        $driver = strtoupper((string) ($connection->DBDriver ?? ''));

        if ($driver !== 'MYSQLI') {
            throw new RuntimeException('Automatic import locking requires a MySQL-compatible database.');
        }

        try {
            $lockName = (string) config(ImportConfig::class)->autoImportLockName;
            $result = $connection->query('SELECT GET_LOCK(?, 0) AS acquired', [$lockName])->getRowArray();
        } catch (Throwable $exception) {
            $connection->close();
            throw $exception;
        }

        if ((int) ($result['acquired'] ?? 0) !== 1) {
            $connection->close();
            return false;
        }

        $this->connection = $connection;
        return true;
    }

    /**
     * Release the advisory lock and close its dedicated connection.
     *
     * @return void
     */
    public function release(): void
    {
        if ($this->connection === null) {
            return;
        }

        try {
            $lockName = (string) config(ImportConfig::class)->autoImportLockName;
            $this->connection->query('SELECT RELEASE_LOCK(?)', [$lockName]);
        } finally {
            $this->connection->close();
            $this->connection = null;
        }
    }
}
