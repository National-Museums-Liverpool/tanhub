<?php

namespace Tests;

use App\Services\Import\Persistence\TaxonNamesImportService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TaxonNamesImportServiceTest extends CIUnitTestCase
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

        $this->backupTable('taxa');
        $this->backupTable('taxon_names');

        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxa');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxon_names');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            taxon_identifier VARCHAR(100) NULL,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxon_names (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid VARCHAR(36) NOT NULL,
            taxon_id INTEGER NOT NULL,
            name VARCHAR(200) NOT NULL,
            given_name_identifier VARCHAR(100) NOT NULL,
            accepted INTEGER NOT NULL DEFAULT 0,
            scientific INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $this->db->table('taxa')->insert([
            'id' => 1,
            'taxon_identifier' => 'TVK-1',
            'deleted_at' => null,
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (! isset($this->db)) {
            return;
        }

        $prefix = $this->db->getPrefix();

        $this->db->query('PRAGMA foreign_keys = OFF');

        try {
            $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxon_names');
            $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxa');

            $this->restoreTable('taxa');
            $this->restoreTable('taxon_names');
        } finally {
            $this->db->query('PRAGMA foreign_keys = ON');
            $this->tableBackups = [];
        }
    }

    public function testImportInsertsNameAndBooleanFlags(): void
    {
        $service = new TaxonNamesImportService();

        $counts = $service->import([
            [
                'taxon_identifier' => 'TVK-1',
                'given_name_identifier' => 'GN-1',
                'name' => str_repeat('Species', 40),
                'accepted' => 'yes',
                'scientific' => true,
            ],
        ]);

        $this->assertSame(1, $counts['inserted']);
        $this->assertSame(1, $counts['processed']);
        $this->assertSame(0, $counts['errors']);

        $row = $this->db->table('taxon_names')->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame(36, strlen((string) $row['uuid']));
        $this->assertSame(200, strlen((string) $row['name']));
        $this->assertSame('1', (string) $row['accepted']);
        $this->assertSame('1', (string) $row['scientific']);
    }

    public function testImportUpdatesExistingTaxonNameByTaxonAndGivenIdentifier(): void
    {
        $this->db->table('taxon_names')->insert([
            'id' => 9,
            'uuid' => '00000000-0000-4000-8000-000000000000',
            'taxon_id' => 1,
            'name' => 'Old name',
            'given_name_identifier' => 'GN-1',
            'accepted' => 0,
            'scientific' => 0,
            'deleted_at' => '2020-01-01 00:00:00',
        ]);

        $service = new TaxonNamesImportService();

        $counts = $service->import([
            [
                'taxon_identifier' => 'TVK-1',
                'given_name_identifier' => 'GN-1',
                'name' => 'Updated name',
                'accepted' => 1,
                'scientific' => 'true',
            ],
        ]);

        $this->assertSame(0, $counts['inserted']);
        $this->assertSame(1, $counts['updated']);

        $row = $this->db->table('taxon_names')->where('id', 9)->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('Updated name', (string) $row['name']);
        $this->assertSame('1', (string) $row['accepted']);
        $this->assertSame('1', (string) $row['scientific']);
        $this->assertNull($row['deleted_at']);
    }

    public function testImportSkipsRowsWithUnknownTaxonIdentifier(): void
    {
        $service = new TaxonNamesImportService();

        $counts = $service->import([
            [
                'taxon_identifier' => 'TVK-UNKNOWN',
                'given_name_identifier' => 'GN-2',
                'name' => 'Unknown taxon',
            ],
        ]);

        $this->assertSame(1, $counts['processed']);
        $this->assertSame(1, $counts['skipped']);
        $this->assertSame(0, $counts['inserted']);
    }

    public function testImportDryRunCountsWithoutPersistingRows(): void
    {
        $service = new TaxonNamesImportService();

        $counts = $service->import([
            [
                'taxon_identifier' => 'TVK-1',
                'given_name_identifier' => 'GN-DRY',
                'name' => 'Dry run name',
                'accepted' => false,
                'scientific' => false,
            ],
        ], true);

        $this->assertSame(1, $counts['inserted']);
        $this->assertSame(1, $counts['processed']);
        $this->assertSame(0, $this->db->table('taxon_names')->countAllResults());
    }

    private function backupTable(string $tableName): void
    {
        $prefix = $this->db->getPrefix();
        $prefixedTableName = $prefix . $tableName;

        if (! $this->db->tableExists($tableName)) {
            return;
        }

        $backupName = '__tn_import_test_backup_' . $tableName;

        $this->db->query('PRAGMA foreign_keys = OFF');

        try {
            $this->db->query('DROP TABLE IF EXISTS ' . $prefix . $backupName);
            $this->db->query('ALTER TABLE ' . $prefixedTableName . ' RENAME TO ' . $prefix . $backupName);
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
