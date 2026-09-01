<?php

namespace Tests;

use App\Services\Import\AutoImportLock;
use CodeIgniter\Test\CIUnitTestCase;
use RuntimeException;

/**
 * Query result double for advisory lock responses.
 */
final class AutoImportLockResultDouble
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
 * MySQL connection double for automatic import lock tests.
 */
final class AutoImportLockConnectionDouble
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
    ) {
    }

    /**
     * Respond to advisory lock acquisition and release queries.
     *
     * @param string                   $sql    SQL statement.
     * @param array<int, string>|null  $params Query parameters.
     *
     * @return AutoImportLockResultDouble Advisory lock query result.
     */
    public function query(string $sql, ?array $params = null): AutoImportLockResultDouble
    {
        if ($this->failOnRelease && str_contains($sql, 'RELEASE_LOCK')) {
            throw new RuntimeException('Release failed.');
        }

        return new AutoImportLockResultDouble($this->acquired);
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
 * Verifies automatic imports retain a process-lifetime local lock.
 *
 * @internal
 */
final class AutoImportLockTest extends CIUnitTestCase
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
        $firstConnection = new AutoImportLockConnectionDouble();
        $secondConnection = new AutoImportLockConnectionDouble();
        $secondFactoryCalls = 0;
        $firstLock = new AutoImportLock(
            static fn (): object => $firstConnection,
            $this->lockFile,
        );
        $secondLock = new AutoImportLock(
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
        $refusedLock = new AutoImportLock(
            static fn (): object => new AutoImportLockConnectionDouble(0),
            $this->lockFile,
        );
        $nextLock = new AutoImportLock(
            static fn (): object => new AutoImportLockConnectionDouble(),
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
        $connection = new AutoImportLockConnectionDouble(1, true);
        $failedReleaseLock = new AutoImportLock(
            static fn (): object => $connection,
            $this->lockFile,
        );
        $nextLock = new AutoImportLock(
            static fn (): object => new AutoImportLockConnectionDouble(),
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
}