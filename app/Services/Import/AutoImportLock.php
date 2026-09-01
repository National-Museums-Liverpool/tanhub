<?php

namespace App\Services\Import;

use Closure;
use Config\Import as ImportConfig;
use RuntimeException;
use Throwable;

/**
 * Prevents concurrent automatic imports using local and database locks.
 */
class AutoImportLock
{
    /**
     * @var resource|null Local file handle holding the process-lifetime lock.
     */
    private $fileHandle = null;

    /**
     * @var object|null Dedicated database connection holding the advisory lock.
     */
    private ?object $connection = null;

    /**
     * Create an automatic import lock.
     *
     * @param Closure(): object|null $connectionFactory Optional database connection factory.
     * @param string|null            $lockFile          Optional local lock file path.
     */
    public function __construct(
        private readonly ?Closure $connectionFactory = null,
        private readonly ?string $lockFile = null,
    ) {
    }

    /**
     * Acquire the local and database automatic import locks without waiting.
     *
     * @return bool True when this process acquired the lock.
     *
     * @throws RuntimeException When locking cannot be initialized or is unsupported.
     */
    public function acquire(): bool
    {
        $config = config(ImportConfig::class);
        $lockFile = $this->lockFile ?? (string) $config->autoImportLockFile;
        $fileHandle = @fopen($lockFile, 'c');

        if ($fileHandle === false) {
            throw new RuntimeException('Unable to open the automatic import lock file: ' . $lockFile);
        }

        if (! flock($fileHandle, LOCK_EX | LOCK_NB)) {
            fclose($fileHandle);
            return false;
        }

        $this->fileHandle = $fileHandle;

        $connectionFactory = $this->connectionFactory ?? static fn (): object => db_connect('default', false);

        try {
            $connection = $connectionFactory();
        } catch (Throwable $exception) {
            $this->releaseFileLock();
            throw $exception;
        }

        $driver = strtoupper((string) ($connection->DBDriver ?? ''));

        if ($driver !== 'MYSQLI') {
            $connection->close();
            $this->releaseFileLock();
            throw new RuntimeException('Automatic import locking requires a MySQL-compatible database.');
        }

        try {
            $lockName = (string) $config->autoImportLockName;
            $result = $connection->query('SELECT GET_LOCK(?, 0) AS acquired', [$lockName])->getRowArray();
        } catch (Throwable $exception) {
            $connection->close();
            $this->releaseFileLock();
            throw $exception;
        }

        if ((int) ($result['acquired'] ?? 0) !== 1) {
            $connection->close();
            $this->releaseFileLock();
            return false;
        }

        $this->connection = $connection;
        return true;
    }

    /**
     * Release the database and local locks and close their resources.
     *
     * @return void
     */
    public function release(): void
    {
        try {
            if ($this->connection !== null) {
                $lockName = (string) config(ImportConfig::class)->autoImportLockName;
                $this->connection->query('SELECT RELEASE_LOCK(?)', [$lockName]);
            }
        } finally {
            if ($this->connection !== null) {
                $this->connection->close();
                $this->connection = null;
            }

            $this->releaseFileLock();
        }
    }

    /**
     * Release and close the local file lock when held.
     *
     * @return void
     */
    private function releaseFileLock(): void
    {
        if (! is_resource($this->fileHandle)) {
            return;
        }

        flock($this->fileHandle, LOCK_UN);
        fclose($this->fileHandle);
        $this->fileHandle = null;
    }
}
