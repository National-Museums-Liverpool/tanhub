<?php

namespace Tests;

use App\Services\Import\Persistence\GridSquareStatsImportService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class GridSquareStatsImportServiceTest extends CIUnitTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = db_connect();
        $prefix = $this->db->getPrefix();

        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'geographic_regions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            higher_geography_identifier INTEGER NOT NULL,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'grid_square_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid VARCHAR(36) NOT NULL,
            square VARCHAR(12) NOT NULL,
            geographic_region_id INTEGER NULL,
            easting INTEGER NOT NULL,
            northing INTEGER NOT NULL,
            lon DECIMAL(10,7) NULL,
            lat DECIMAL(10,7) NULL,
            partial INTEGER NOT NULL DEFAULT 0,
            occurrences_count INTEGER NOT NULL DEFAULT 0,
            species_count INTEGER NOT NULL DEFAULT 0
        )');
    }

    public function testImportPersistsGridSquareStatsRows(): void
    {
        $this->db->table('geographic_regions')->insert([
            'higher_geography_identifier' => 991234,
            'deleted_at' => null,
        ]);

        $service = new GridSquareStatsImportService();
        $counts = $service->import([
            [
                'location_id' => 991234,
                'square' => 'su99a',
                'centre_easting' => 412300,
                'centre_northing' => 112300,
                'centre_lat' => '50.1234567',
                'centre_lon' => '-1.2345678',
                'partial' => 1,
            ],
        ]);

        $this->assertSame(1, $counts['fetched']);
        $this->assertSame(1, $counts['processed']);
        $this->assertSame(1, $counts['inserted']);
        $this->assertSame(0, $counts['updated']);
        $this->assertSame(0, $counts['skipped']);
        $this->assertSame(0, $counts['errors']);

        $row = $this->db->table('grid_square_stats')->getWhere(['square' => 'SU99A'])->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('SU99A', $row['square']);
        $this->assertSame(1, (int) $row['geographic_region_id']);
        $this->assertSame(412300, (int) $row['easting']);
        $this->assertSame(112300, (int) $row['northing']);
        $this->assertSame('50.1234567', (string) $row['lat']);
        $this->assertSame('-1.2345678', (string) $row['lon']);
        $this->assertSame(1, (int) $row['partial']);
        $this->assertSame(36, strlen((string) $row['uuid']));
    }

    public function testImportSkipsRowsWithoutMatchingGeographicRegion(): void
    {
        $service = new GridSquareStatsImportService();
        $counts = $service->import([
            [
                'location_id' => 999999,
                'square' => 'su9999',
                'centre_easting' => 412300,
                'centre_northing' => 112300,
                'centre_lat' => '50.1234567',
                'centre_lon' => '-1.2345678',
                'partial' => 0,
            ],
        ]);

        $this->assertSame(1, $counts['fetched']);
        $this->assertSame(1, $counts['processed']);
        $this->assertSame(0, $counts['inserted']);
        $this->assertSame(0, $counts['updated']);
        $this->assertSame(1, $counts['skipped']);
        $this->assertSame(0, $counts['errors']);
        $this->assertSame([], $this->db->table('grid_square_stats')->getWhere(['square' => 'SU9999'])->getResultArray());
    }
}
