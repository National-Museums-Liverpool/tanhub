<?php

namespace Tests;

use App\Services\Import\Persistence\OccurrenceImportService;
use App\Services\Import\Support\OsgbGridReferenceBuilder;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class OccurrenceImportServiceTest extends CIUnitTestCase
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

        $this->backupTable('occurrences');
        $this->backupTable('taxon_names');
        $this->backupTable('taxa');

        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'occurrences');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxon_names');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxa');

        $rankColumns = $this->rankColumns();
        $taxaRankColumnsSql = $this->rankColumnsSql($rankColumns);
        $occurrenceRankColumnsSql = $this->rankColumnsSql($rankColumns);

        $this->db->query('CREATE TABLE ' . $prefix . 'taxa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            scientific_name_identifier VARCHAR(100) NOT NULL,
            deleted_at DATETIME NULL' . $taxaRankColumnsSql . '
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxon_names (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            given_name_identifier VARCHAR(100) NOT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'occurrences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            unique_key VARCHAR(100) NOT NULL,
            taxon_id INTEGER NOT NULL,
            taxon_name_id INTEGER NOT NULL,
            from_date DATE NULL,
            to_date DATE NULL,
            grid_ref VARCHAR(20) NOT NULL,
            grid_ref_2km CHAR(5) NULL,
            locality VARCHAR(255) NULL,
            recorded_by VARCHAR(255) NOT NULL,
            identified_by VARCHAR(255) NULL,
            identification_verification_status VARCHAR(2) NOT NULL,
            sex VARCHAR(20) NULL,
            life_stage VARCHAR(20) NULL,
            organism_quantity VARCHAR(20) NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            data_source_id INTEGER NOT NULL,
            blocked INTEGER NOT NULL DEFAULT 0,
            blocked_reason TEXT NULL,
            created_at DATETIME NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL' . $occurrenceRankColumnsSql . '
        )');

        $this->db->table('taxa')->insert([
            'id' => 1,
            'scientific_name_identifier' => 'TVK-1',
            'deleted_at' => null,
        ]);

        $this->db->table('taxon_names')->insert([
            'id' => 1,
            'given_name_identifier' => 'GIVEN-1',
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
            $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'occurrences');
            $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxon_names');
            $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxa');

            $this->restoreTable('taxa');
            $this->restoreTable('taxon_names');
            $this->restoreTable('occurrences');
        } finally {
            $this->db->query('PRAGMA foreign_keys = ON');
            $this->tableBackups = [];
        }
    }

    public function testImportGeneratesDintyGridReferenceForNonOsgbRecords(): void
    {
        $service = new OccurrenceImportService(new OsgbGridReferenceBuilder());

        $counts = $service->import([
            [
                'remote_id' => 'R1',
                'scientific_name_identifier' => 'TVK-1',
                'given_name_identifier' => 'GIVEN-1',
                'grid_ref' => 'SHOULD_BE_REPLACED',
                'grid_ref_system' => 'WGS84',
                'coordinate_uncertainty_in_meters' => 1500,
                'latitude' => 53.4808,
                'longitude' => -2.2426,
            ],
        ], 2, 'NBN');

        $this->assertSame(1, $counts['inserted']);
        $this->assertSame(0, $counts['skipped']);

        $row = $this->db->table('occurrences')->getWhere(['unique_key' => 'NBN:R1'])->getRowArray();

        $this->assertNotNull($row);
        $this->assertMatchesRegularExpression('/^[A-Z]{2}\d{2}[A-HJ-Z]$/', (string) $row['grid_ref']);
        $this->assertSame($row['grid_ref'], $row['grid_ref_2km']);
    }

    public function testImportGeneratesTenKilometreGridReferenceWhenUncertaintyRequiresIt(): void
    {
        $service = new OccurrenceImportService(new OsgbGridReferenceBuilder());

        $counts = $service->import([
            [
                'remote_id' => 'R2',
                'scientific_name_identifier' => 'TVK-1',
                'given_name_identifier' => 'GIVEN-1',
                'grid_ref' => 'SHOULD_BE_REPLACED',
                'grid_ref_system' => 'EPSG:4326',
                'coordinate_uncertainty_in_meters' => 8000,
                'latitude' => 53.4808,
                'longitude' => -2.2426,
            ],
        ], 2, 'NBN');

        $this->assertSame(1, $counts['inserted']);
        $this->assertSame(0, $counts['skipped']);

        $row = $this->db->table('occurrences')->getWhere(['unique_key' => 'NBN:R2'])->getRowArray();

        $this->assertNotNull($row);
        $this->assertMatchesRegularExpression('/^[A-Z]{2}\d{2}$/', (string) $row['grid_ref']);
        $this->assertSame('', (string) $row['grid_ref_2km']);
    }

    public function testImportSkipsNonOsgbRecordWhenCoordinatesAreMissing(): void
    {
        $service = new OccurrenceImportService(new OsgbGridReferenceBuilder());

        $counts = $service->import([
            [
                'remote_id' => 'R3',
                'scientific_name_identifier' => 'TVK-1',
                'given_name_identifier' => 'GIVEN-1',
                'grid_ref' => 'SU1234',
                'grid_ref_system' => 'WGS84',
                'coordinate_uncertainty_in_meters' => 1500,
                'latitude' => null,
                'longitude' => null,
            ],
        ], 2, 'NBN');

        $this->assertSame(0, $counts['inserted']);
        $this->assertSame(1, $counts['skipped']);
        $this->assertSame(0, $counts['errors']);
        $this->assertSame(0, $this->db->table('occurrences')->countAllResults());
    }

    public function testImportKeepsOsgbGridReferenceUntouched(): void
    {
        $service = new OccurrenceImportService(new OsgbGridReferenceBuilder());

        $counts = $service->import([
            [
                'remote_id' => 'R4',
                'scientific_name_identifier' => 'TVK-1',
                'given_name_identifier' => 'GIVEN-1',
                'grid_ref' => 'SU1234',
                'grid_ref_system' => 'OSGB',
                'grid_ref_2km' => 'SU13L',
                'latitude' => 53.4808,
                'longitude' => -2.2426,
            ],
        ], 2, 'NBN');

        $this->assertSame(1, $counts['inserted']);
        $this->assertSame(0, $counts['skipped']);

        $row = $this->db->table('occurrences')->getWhere(['unique_key' => 'NBN:R4'])->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('SU1234', (string) $row['grid_ref']);
        $this->assertSame('SU13L', (string) $row['grid_ref_2km']);
    }

    /**
     * @return array<int, string>
     */
    private function rankColumns(): array
    {
        $ranks = config('Import')->taxonRanks;
        $ranks = is_array($ranks) ? $ranks : explode(',', (string) $ranks);

        return array_map(static fn ($rank): string => strtolower((string) $rank) . '_id', $ranks);
    }

    /**
     * @param array<int, string> $columns
     */
    private function rankColumnsSql(array $columns): string
    {
        if ($columns === []) {
            return '';
        }

        $sql = '';

        foreach ($columns as $column) {
            $sql .= ",\n            " . $column . ' INTEGER NULL';
        }

        return $sql;
    }

    /**
     * Rename an existing table out of the way before test-local schema is created.
     *
     * @param string $tableName Table name without prefix.
     */
    private function backupTable(string $tableName): void
    {
        $prefix = $this->db->getPrefix();
        $prefixedTableName = $prefix . $tableName;

        if (! $this->db->tableExists($tableName)) {
            return;
        }

        $backupName = '__occ_import_test_backup_' . $tableName;
        $prefixedBackupName = $prefix . $backupName;

        $this->db->query('PRAGMA foreign_keys = OFF');

        try {
            $this->db->query('DROP TABLE IF EXISTS ' . $prefixedBackupName);
            $this->db->query('ALTER TABLE ' . $prefixedTableName . ' RENAME TO ' . $prefixedBackupName);
            $this->tableBackups[$tableName] = $backupName;
        } finally {
            $this->db->query('PRAGMA foreign_keys = ON');
        }
    }

    /**
     * Restore a previously backed-up table.
     *
     * @param string $tableName Table name without prefix.
     */
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
