<?php

namespace Tests;

use App\Services\Import\Persistence\TaxonRanksImportService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TaxonRanksImportServiceTest extends CIUnitTestCase
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

        $this->backupTable('taxon_ranks');

        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxon_ranks');
        $this->db->query('CREATE TABLE ' . $prefix . 'taxon_ranks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            rank VARCHAR(50) NOT NULL,
            abbr VARCHAR(50) NOT NULL,
            sort_order INTEGER NOT NULL DEFAULT 0
        )');
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if (! isset($this->db)) {
            return;
        }

        $prefix = $this->db->getPrefix();

        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxon_ranks');
        $this->restoreTable('taxon_ranks');
        $this->tableBackups = [];
    }

    public function testImportInsertsAndGeneratesAbbrWhenMissing(): void
    {
        $service = new TaxonRanksImportService();

        $counts = $service->import([
            [
                'rank' => 'Species Group',
                'sort_order' => -1,
            ],
        ]);

        $this->assertSame(1, $counts['inserted']);
        $this->assertSame(1, $counts['processed']);

        $row = $this->db->table('taxon_ranks')->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('species_group', (string) $row['abbr']);
        $this->assertSame('0', (string) $row['sort_order']);
    }

    public function testImportUpdatesExistingByRank(): void
    {
        $this->db->table('taxon_ranks')->insert([
            'id' => 5,
            'rank' => 'Family',
            'abbr' => 'fam',
            'sort_order' => 2,
        ]);

        $service = new TaxonRanksImportService();

        $counts = $service->import([
            [
                'rank' => 'Family',
                'abbr' => 'family',
                'sort_order' => 7,
            ],
        ]);

        $this->assertSame(0, $counts['inserted']);
        $this->assertSame(1, $counts['updated']);

        $row = $this->db->table('taxon_ranks')->where('id', 5)->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('family', (string) $row['abbr']);
        $this->assertSame('7', (string) $row['sort_order']);
    }

    public function testImportSkipsRowsWithEmptyRank(): void
    {
        $service = new TaxonRanksImportService();

        $counts = $service->import([
            [
                'rank' => ' ',
                'abbr' => 'x',
            ],
        ]);

        $this->assertSame(1, $counts['processed']);
        $this->assertSame(1, $counts['skipped']);
        $this->assertSame(0, $counts['inserted']);
    }

    public function testImportDryRunCountsWithoutPersistingRows(): void
    {
        $service = new TaxonRanksImportService();

        $counts = $service->import([
            [
                'rank' => 'Order',
                'abbr' => 'ord',
                'sort_order' => 8,
            ],
        ], true);

        $this->assertSame(1, $counts['inserted']);
        $this->assertSame(1, $counts['processed']);
        $this->assertSame(0, $this->db->table('taxon_ranks')->countAllResults());
    }

    private function backupTable(string $tableName): void
    {
        $prefix = $this->db->getPrefix();

        if (! $this->db->tableExists($tableName)) {
            return;
        }

        $backupName = '__tr_import_test_backup_' . $tableName;

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
