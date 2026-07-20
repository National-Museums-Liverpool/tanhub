<?php

namespace Tests;

use App\Services\Import\Persistence\GeographicRegionsImportService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class GeographicRegionsImportServiceTest extends CIUnitTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = db_connect();
        $prefix = $this->db->getPrefix();

        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'data_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            abbr VARCHAR(10) NOT NULL,
            title VARCHAR(100) NOT NULL,
            url VARCHAR(100) NOT NULL
        )');

        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'geographic_regions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            higher_geography_identifier INTEGER NOT NULL,
            higher_geography VARCHAR(100) NOT NULL,
            location_type VARCHAR(100) NOT NULL,
            polygon_geometry TEXT NULL,
            data_source_id INTEGER NOT NULL,
            deleted_at DATETIME NULL
        )');

        $this->db->table('geographic_regions')->emptyTable();
        $this->db->table('data_sources')->emptyTable();
    }

    public function testImportPersistsPolygonGeometry(): void
    {
        $this->db->table('data_sources')->insert([
            'id' => 1,
            'abbr' => 'IREC',
            'title' => 'Indicia',
            'url' => 'https://example.org',
        ]);

        $service = new GeographicRegionsImportService();
        $counts = $service->import([
            [
                'higher_geography_identifier' => 123,
                'higher_geography' => 'Region 123',
                'location_type' => 'Vice County',
                'polygon_geometry' => 'POLYGON((-1 51,-1 52,0 52,0 51,-1 51))',
                'data_source_abbr' => 'IREC',
            ],
        ]);

        $this->assertSame(1, $counts['inserted']);
        $this->assertSame(0, $counts['updated']);
        $this->assertSame(0, $counts['skipped']);
        $this->assertSame(0, $counts['errors']);

        $row = $this->db->table('geographic_regions')->getWhere(['higher_geography_identifier' => 123])->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('Region 123', (string) $row['higher_geography']);
        $this->assertSame('Vice County', (string) $row['location_type']);
        $this->assertSame('POLYGON((-1 51,-1 52,0 52,0 51,-1 51))', (string) $row['polygon_geometry']);
    }
}
