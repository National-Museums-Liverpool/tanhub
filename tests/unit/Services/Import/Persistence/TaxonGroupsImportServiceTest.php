<?php

namespace Tests;

use App\Services\Import\Persistence\TaxonGroupsImportService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TaxonGroupsImportServiceTest extends CIUnitTestCase
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

        $this->backupTable('taxon_groups');

        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxon_groups');
        $this->db->query('CREATE TABLE ' . $prefix . 'taxon_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(200) NOT NULL,
            external_key VARCHAR(100) NOT NULL,
            indicia_taxon_group_id INTEGER NOT NULL,
            implied INTEGER NOT NULL DEFAULT 0,
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

        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxon_groups');
        $this->restoreTable('taxon_groups');
        $this->tableBackups = [];
    }

    public function testImportInsertsAndNormalisesImpliedFlag(): void
    {
        $service = new TaxonGroupsImportService();

        $counts = $service->import([
            [
                'title' => str_repeat('Group', 60),
                'external_key' => str_repeat('external-', 20),
                'indicia_taxon_group_id' => 42,
                'implied' => 'yes',
            ],
        ]);

        $this->assertSame(1, $counts['inserted']);
        $this->assertSame(1, $counts['processed']);

        $row = $this->db->table('taxon_groups')->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame(200, strlen((string) $row['title']));
        $this->assertSame(100, strlen((string) $row['external_key']));
        $this->assertSame('1', (string) $row['implied']);
    }

    public function testImportUpdatesExistingByExternalKey(): void
    {
        $this->db->table('taxon_groups')->insert([
            'id' => 7,
            'title' => 'Existing',
            'external_key' => 'group-1',
            'indicia_taxon_group_id' => 10,
            'implied' => 1,
            'deleted_at' => '2020-01-01 00:00:00',
        ]);

        $service = new TaxonGroupsImportService();

        $counts = $service->import([
            [
                'title' => 'Updated group',
                'external_key' => 'group-1',
                'indicia_taxon_group_id' => 11,
                'implied' => 'no',
            ],
        ]);

        $this->assertSame(0, $counts['inserted']);
        $this->assertSame(1, $counts['updated']);

        $row = $this->db->table('taxon_groups')->where('id', 7)->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('Updated group', (string) $row['title']);
        $this->assertSame('11', (string) $row['indicia_taxon_group_id']);
        $this->assertSame('0', (string) $row['implied']);
        $this->assertNull($row['deleted_at']);
    }

    public function testImportSkipsRowsMissingRequiredFields(): void
    {
        $service = new TaxonGroupsImportService();

        $counts = $service->import([
            [
                'title' => 'No key',
                'external_key' => '',
                'indicia_taxon_group_id' => 1,
            ],
            [
                'title' => '',
                'external_key' => 'group-2',
                'indicia_taxon_group_id' => 2,
            ],
            [
                'title' => 'No source id',
                'external_key' => 'group-3',
                'indicia_taxon_group_id' => 0,
            ],
        ]);

        $this->assertSame(3, $counts['processed']);
        $this->assertSame(3, $counts['skipped']);
        $this->assertSame(0, $counts['inserted']);
    }

    public function testImportDryRunCountsWithoutPersistingRows(): void
    {
        $service = new TaxonGroupsImportService();

        $counts = $service->import([
            [
                'title' => 'Dry run group',
                'external_key' => 'dry-group',
                'indicia_taxon_group_id' => 14,
                'implied' => true,
            ],
        ], true);

        $this->assertSame(1, $counts['inserted']);
        $this->assertSame(1, $counts['processed']);
        $this->assertSame(0, $this->db->table('taxon_groups')->countAllResults());
    }

    private function backupTable(string $tableName): void
    {
        $prefix = $this->db->getPrefix();

        if (! $this->db->tableExists($tableName)) {
            return;
        }

        $backupName = '__tg_import_test_backup_' . $tableName;

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
