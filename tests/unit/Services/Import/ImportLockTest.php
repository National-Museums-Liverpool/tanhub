<?php

namespace Tests;

use App\Services\Import\ImportLock;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * Query result double for advisory lock responses.
 */
final class ImportLockResultDouble
{
    /**
     * Create an advisory lock result.
     *
     * @param int $acquired Whether the advisory lock was acquired.
     */
    public function __construct(private readonly int $acquired)
    {
    }

    /**
     * Return the advisory lock result row.
     *
     * @return array<string, int> Advisory lock result.
     */
    public function getRowArray(): array
    {
        return ['acquired' => $this->acquired];
    }
}

/**
 * Database connection double for import lock tests.
 */
final class ImportLockConnectionDouble
{
    /**
     * @var string Database driver name.
     */
    public string $DBDriver = 'MySQLi';

    /**
     * @var bool Whether the connection has been closed.
     */
    public bool $closed = false;

    /**
     * Create a database connection double.
     *
     * @param int  $acquired      Whether the advisory lock is acquired.
     * @param bool $failOnRelease Whether releasing the advisory lock throws.
     */
    public function __construct(
        private readonly int $acquired = 1,
        private readonly bool $failOnRelease = false,
        string $driver = 'MySQLi',
    ) {
        $this->DBDriver = $driver;
    }

    /**
     * Respond to advisory lock acquisition and release queries.
     *
     * @param string                   $sql    SQL statement.
     * @param array<int, string>|null  $params Query parameters.
     *
    * @return ImportLockResultDouble Advisory lock query result.
     */
    public function query(string $sql, ?array $params = null): ImportLockResultDouble
    {
        if ($this->failOnRelease && str_contains($sql, 'RELEASE_LOCK')) {
            throw new RuntimeException('Release failed.');
        }

        return new ImportLockResultDouble($this->acquired);
    }

    /**
     * Mark the connection as closed.
     *
     * @return void
     */
    public function close(): void
    {
        $this->closed = true;
    }
}

/**
 * Verifies imports retain a process-lifetime exclusive lock.
 *
 * @internal
 */
final class ImportLockTest extends CIUnitTestCase
{
    /**
     * @var string Lock file path for the current test.
     */
    private string $lockFile;

    /**
     * Create a unique lock file for each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $lockFile = tempnam(sys_get_temp_dir(), 'tanhub-import-lock-');
        $this->assertIsString($lockFile);
        $this->lockFile = $lockFile;
    }

    /**
     * Remove the test lock file.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        if (isset($this->lockFile) && is_file($this->lockFile)) {
            unlink($this->lockFile);
        }

        parent::tearDown();
    }

    /**
     * Prevent a second process from reaching MySQL while the local lock is held.
     *
     * @return void
     */
    public function testLocalLockPreventsConcurrentAcquisition(): void
    {
        $firstConnection = new ImportLockConnectionDouble();
        $secondConnection = new ImportLockConnectionDouble();
        $secondFactoryCalls = 0;
        $firstLock = new ImportLock(
            static fn (): object => $firstConnection,
            $this->lockFile,
        );
        $secondLock = new ImportLock(
            static function () use ($secondConnection, &$secondFactoryCalls): object {
                $secondFactoryCalls++;
                return $secondConnection;
            },
            $this->lockFile,
        );

        $this->assertTrue($firstLock->acquire());
        $this->assertFalse($secondLock->acquire());
        $this->assertSame(0, $secondFactoryCalls);

        $firstLock->release();

        $this->assertTrue($secondLock->acquire());
        $this->assertSame(1, $secondFactoryCalls);
        $secondLock->release();
    }

    /**
     * Release the local lock when MySQL refuses the advisory lock.
     *
     * @return void
     */
    public function testDatabaseLockRefusalReleasesLocalLock(): void
    {
        $refusedLock = new ImportLock(
            static fn (): object => new ImportLockConnectionDouble(0),
            $this->lockFile,
        );
        $nextLock = new ImportLock(
            static fn (): object => new ImportLockConnectionDouble(),
            $this->lockFile,
        );

        $this->assertFalse($refusedLock->acquire());
        $this->assertTrue($nextLock->acquire());
        $nextLock->release();
    }

    /**
     * Release the local lock even when the MySQL release query fails.
     *
     * @return void
     */
    public function testDatabaseReleaseFailureStillReleasesLocalLock(): void
    {
        $connection = new ImportLockConnectionDouble(1, true);
        $failedReleaseLock = new ImportLock(
            static fn (): object => $connection,
            $this->lockFile,
        );
        $nextLock = new ImportLock(
            static fn (): object => new ImportLockConnectionDouble(),
            $this->lockFile,
        );

        $this->assertTrue($failedReleaseLock->acquire());

        try {
            $failedReleaseLock->release();
            $this->fail('Expected the database release query to fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Release failed.', $exception->getMessage());
        }

        $this->assertTrue($connection->closed);
        $this->assertTrue($nextLock->acquire());
        $nextLock->release();
    }

    /**
     * Enforce local exclusion when database advisory locks are unavailable.
     *
     * @return void
     */
    public function testNonMysqlConnectionsStillUseLocalLock(): void
    {
        $firstLock = new ImportLock(
            static fn (): object => new ImportLockConnectionDouble(1, false, 'SQLite3'),
            $this->lockFile,
        );
        $secondLock = new ImportLock(
            static fn (): object => new ImportLockConnectionDouble(1, false, 'SQLite3'),
            $this->lockFile,
        );

        $this->assertTrue($firstLock->acquire());
        $this->assertFalse($secondLock->acquire());

        $firstLock->release();

        $this->assertTrue($secondLock->acquire());
        $secondLock->release();
    }
}