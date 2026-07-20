<?php

namespace Tests;

use App\Services\Import\Persistence\GeographicRegionsOccurrenceImportService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class GeographicRegionsOccurrenceImportServiceTest extends CIUnitTestCase
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
            higher_geography VARCHAR(100) NOT NULL,
            location_type VARCHAR(100) NOT NULL,
            polygon_geometry TEXT NULL,
            data_source_id INTEGER NOT NULL,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'occurrences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            blocked INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'geographic_regions_occurrences (
            geographic_region_id INTEGER NOT NULL,
            occurrence_id INTEGER NOT NULL
        )');

        $this->db->table('geographic_regions')->emptyTable();
        $this->db->table('occurrences')->emptyTable();
        $this->db->table('geographic_regions_occurrences')->emptyTable();
    }

    public function testRunRebuildsAssignmentsAndIsIdempotent(): void
    {
        $this->db->table('geographic_regions')->insertBatch([
            [
                'id' => 1,
                'higher_geography_identifier' => 101,
                'higher_geography' => 'Region A',
                'location_type' => 'Vice County',
                'polygon_geometry' => 'POLYGON((0 0,2 0,2 2,0 2,0 0))',
                'data_source_id' => 1,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'higher_geography_identifier' => 102,
                'higher_geography' => 'Region B',
                'location_type' => 'Vice County',
                'polygon_geometry' => 'POLYGON((1 1,3 1,3 3,1 3,1 1))',
                'data_source_id' => 1,
                'deleted_at' => null,
            ],
        ]);

        $this->db->table('occurrences')->insertBatch([
            [
                'id' => 11,
                'latitude' => 1.5,
                'longitude' => 1.5,
                'blocked' => 0,
                'deleted_at' => null,
            ],
            [
                'id' => 12,
                'latitude' => 4.0,
                'longitude' => 4.0,
                'blocked' => 0,
                'deleted_at' => null,
            ],
            [
                'id' => 13,
                'latitude' => 1.5,
                'longitude' => 1.5,
                'blocked' => 1,
                'deleted_at' => null,
            ],
        ]);

        $service = new GeographicRegionsOccurrenceImportService();
        $firstRun = $service->run(false);

        $this->assertSame('success', $firstRun['status']);
        $this->assertSame(2, $firstRun['inserted']);
        $this->assertSame(2, $firstRun['processed']);
        $this->assertSame(0, $firstRun['errors']);

        $rows = $this->db->table('geographic_regions_occurrences')->orderBy('geographic_region_id', 'asc')->get()->getResultArray();

        $this->assertCount(2, $rows);
        $this->assertSame([
            ['geographic_region_id' => '1', 'occurrence_id' => '11'],
            ['geographic_region_id' => '2', 'occurrence_id' => '11'],
        ], array_map(static fn (array $row): array => [
            'geographic_region_id' => (string) $row['geographic_region_id'],
            'occurrence_id' => (string) $row['occurrence_id'],
        ], $rows));

        $secondRun = $service->run(false);

        $this->assertSame('success', $secondRun['status']);
        $this->assertSame(2, $secondRun['inserted']);
        $this->assertSame(0, $secondRun['errors']);

        $rowsAfterSecondRun = $this->db->table('geographic_regions_occurrences')->orderBy('geographic_region_id', 'asc')->get()->getResultArray();

        $this->assertCount(2, $rowsAfterSecondRun);
    }
}
