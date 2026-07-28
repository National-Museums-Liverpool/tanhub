<?php

namespace Tests;

use App\Services\Import\Persistence\RecordingSchemesImportService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class RecordingSchemesImportServiceTest extends CIUnitTestCase
{
    protected $db;

    /**
     * @var array<string, string>
     */
    private array $tableBackups = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = db_connect();
        $prefix = $this->db->getPrefix();

        $this->backupTable('recording_schemes');

        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'recording_schemes');
        $this->db->query('CREATE TABLE ' . $prefix . 'recording_schemes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            external_key VARCHAR(16) NOT NULL,
            title VARCHAR(100) NOT NULL,
            description VARCHAR(255) NULL,
            deleted_at DATETIME NULL
        )');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (! isset($this->db)) {
            return;
        }

        $prefix = $this->db->getPrefix();

        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'recording_schemes');
        $this->restoreTable('recording_schemes');
        $this->tableBackups = [];
    }

    public function testImportInsertsAndTruncatesValues(): void
    {
        $service = new RecordingSchemesImportService();

        $counts = $service->import([
            [
                'external_key' => '  long-external-key-value  ',
                'title' => str_repeat('Title', 30),
                'description' => str_repeat('Desc', 100),
            ],
        ]);

        $this->assertSame(1, $counts['inserted']);
        $this->assertSame(1, $counts['processed']);
        $this->assertSame(0, $counts['errors']);

        $row = $this->db->table('recording_schemes')->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame(16, strlen((string) $row['external_key']));
        $this->assertSame(100, strlen((string) $row['title']));
        $this->assertSame(255, strlen((string) $row['description']));
    }

    public function testImportUpdatesExistingByExternalKey(): void
    {
        $this->db->table('recording_schemes')->insert([
            'id' => 11,
            'external_key' => 'scheme-a',
            'title' => 'Old title',
            'description' => 'Old description',
            'deleted_at' => '2020-01-01 00:00:00',
        ]);

        $service = new RecordingSchemesImportService();

        $counts = $service->import([
            [
                'external_key' => 'scheme-a',
                'title' => 'New title',
                'description' => 'New description',
            ],
        ]);

        $this->assertSame(0, $counts['inserted']);
        $this->assertSame(1, $counts['updated']);

        $row = $this->db->table('recording_schemes')->where('id', 11)->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('New title', (string) $row['title']);
        $this->assertSame('New description', (string) $row['description']);
        $this->assertNull($row['deleted_at']);
    }

    public function testImportDryRunCountsWithoutPersistingRows(): void
    {
        $service = new RecordingSchemesImportService();

        $counts = $service->import([
            [
                'external_key' => 'scheme-b',
                'title' => 'Scheme B',
                'description' => 'Dry run item',
            ],
        ], true);

        $this->assertSame(1, $counts['inserted']);
        $this->assertSame(1, $counts['processed']);
        $this->assertSame(0, $this->db->table('recording_schemes')->countAllResults());
    }

    public function testImportSkipsRowsMissingRequiredFields(): void
    {
        $service = new RecordingSchemesImportService();

        $counts = $service->import([
            [
                'external_key' => '',
                'title' => 'Missing key',
            ],
            [
                'external_key' => 'scheme-c',
                'title' => '',
            ],
        ]);

        $this->assertSame(2, $counts['processed']);
        $this->assertSame(2, $counts['skipped']);
        $this->assertSame(0, $counts['inserted']);
        $this->assertSame(0, $this->db->table('recording_schemes')->countAllResults());
    }

    private function backupTable(string $tableName): void
    {
        $prefix = $this->db->getPrefix();

        if (! $this->db->tableExists($tableName)) {
            return;
        }

        $backupName = '__rs_import_test_backup_' . $tableName;

        $this->db->query('PRAGMA foreign_keys = OFF');

        try {
            $this->db->query('DROP TABLE IF EXISTS ' . $prefix . $backupName);
            $this->db->query('ALTER TABLE ' . $prefix . $tableName . ' RENAME TO ' . $prefix . $backupName);
            $this->tableBackups[$tableName] = $backupName;
        } finally {
            $this->db->query('PRAGMA foreign_keys = ON');
        }
    }

    private function restoreTable(string $tableName): void
    {
        $backupName = $this->tableBackups[$tableName] ?? null;

        if ($backupName === null) {
            return;
        }

        $prefix = $this->db->getPrefix();
        $this->db->query('ALTER TABLE ' . $prefix . $backupName . ' RENAME TO ' . $prefix . $tableName);
    }
}
