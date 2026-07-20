<?php

namespace Tests;

use App\Services\Stats\GridSquareStatsCountsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class GridSquareStatsCountsServiceTest extends CIUnitTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = db_connect();
        $prefix = $this->db->getPrefix();

        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'geographic_regions_occurrences');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'grid_square_stats');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'occurrences');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxon_names');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxa');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxon_groups');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxon_ranks');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'geographic_regions');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'data_sources');

        $this->db->query('CREATE TABLE ' . $prefix . 'data_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            abbr VARCHAR(10) NOT NULL,
            title VARCHAR(100) NOT NULL,
            url VARCHAR(100) NOT NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxon_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(200) NOT NULL,
            indicia_taxon_group_id INTEGER NOT NULL,
            implied INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxon_ranks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            rank VARCHAR(50) NOT NULL,
            abbr VARCHAR(50) NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            taxon_identifier VARCHAR(100) NOT NULL,
            scientific_name_identifier VARCHAR(100) NOT NULL,
            scientific_name VARCHAR(200) NOT NULL,
            scientific_name_authorship VARCHAR(100) NULL,
            vernacular_name VARCHAR(200) NOT NULL,
            taxon_rank_id INTEGER NULL,
            taxon_group_id INTEGER NULL,
            species_id INTEGER NULL,
            rarity_group_name VARCHAR(100) NULL,
            blocked INTEGER NOT NULL DEFAULT 0,
            blocked_reason TEXT NULL,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxon_names (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid CHAR(36) NOT NULL,
            taxon_id INTEGER NOT NULL,
            name VARCHAR(200) NOT NULL,
            given_name_identifier VARCHAR(100) NOT NULL,
            accepted INTEGER NOT NULL DEFAULT 0,
            scientific INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'geographic_regions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            higher_geography_identifier INTEGER NOT NULL,
            higher_geography VARCHAR(100) NOT NULL,
            location_type VARCHAR(100) NOT NULL,
            footprint_geometry TEXT NULL,
            data_source_id INTEGER NOT NULL,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'occurrences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            unique_key VARCHAR(100) NULL,
            taxon_id INTEGER NOT NULL,
            taxon_name_id INTEGER NULL,
            from_date DATE NULL,
            to_date DATE NULL,
            grid_ref VARCHAR(20) NULL,
            grid_ref_2km VARCHAR(5) NULL,
            locality VARCHAR(255) NULL,
            recorded_by VARCHAR(255) NULL,
            identified_by VARCHAR(255) NULL,
            identification_verification_status VARCHAR(2) NULL,
            sex VARCHAR(20) NULL,
            life_stage VARCHAR(20) NULL,
            organism_quantity VARCHAR(20) NULL,
            data_source_id INTEGER NULL,
            latitude DECIMAL(10,7) NULL,
            longitude DECIMAL(10,7) NULL,
            blocked INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'geographic_regions_occurrences (
            geographic_region_id INTEGER NOT NULL,
            occurrence_id INTEGER NOT NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'grid_square_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid CHAR(36) NOT NULL,
            square VARCHAR(12) NOT NULL,
            geographic_region_id INTEGER NULL,
            easting INTEGER NOT NULL DEFAULT 0,
            northing INTEGER NOT NULL DEFAULT 0,
            lon DECIMAL(10,7) NULL,
            lat DECIMAL(10,7) NULL,
            partial INTEGER NOT NULL DEFAULT 0,
            occurrences_count INTEGER NOT NULL DEFAULT 0,
            species_count INTEGER NOT NULL DEFAULT 0
        )');

        $this->db->table('data_sources')->emptyTable();
        $this->db->table('geographic_regions_occurrences')->emptyTable();
        $this->db->table('occurrences')->emptyTable();
        $this->db->table('taxon_names')->emptyTable();
        $this->db->table('taxa')->emptyTable();
        $this->db->table('taxon_groups')->emptyTable();
        $this->db->table('taxon_ranks')->emptyTable();
        $this->db->table('grid_square_stats')->emptyTable();
        $this->db->table('geographic_regions')->emptyTable();
    }

    public function testRunUpdatesCountsFromActiveOccurrencesOnly(): void
    {
        $this->seedTaxa();
        $this->seedDataSource();
        $this->seedTaxonNames();
        $this->seedGeographicRegions();
        $this->seedGridSquareStats();

        $this->db->table('occurrences')->insertBatch([
            ['id' => 1, 'unique_key' => 'TEST:1', 'taxon_id' => 1, 'taxon_name_id' => 1, 'grid_ref' => 'SU99A1234', 'grid_ref_2km' => 'SU99A', 'recorded_by' => 'Tester', 'identification_verification_status' => 'V', 'data_source_id' => 1, 'blocked' => 0, 'deleted_at' => null],
            ['id' => 2, 'unique_key' => 'TEST:2', 'taxon_id' => 2, 'taxon_name_id' => 2, 'grid_ref' => 'SU99A1234', 'grid_ref_2km' => 'SU99A', 'recorded_by' => 'Tester', 'identification_verification_status' => 'V', 'data_source_id' => 1, 'blocked' => 0, 'deleted_at' => null],
            ['id' => 3, 'unique_key' => 'TEST:3', 'taxon_id' => 3, 'taxon_name_id' => 3, 'grid_ref' => 'SU99A1234', 'grid_ref_2km' => 'SU99A', 'recorded_by' => 'Tester', 'identification_verification_status' => 'V', 'data_source_id' => 1, 'blocked' => 0, 'deleted_at' => null],
            ['id' => 4, 'unique_key' => 'TEST:4', 'taxon_id' => 1, 'taxon_name_id' => 1, 'grid_ref' => 'SU99A1234', 'grid_ref_2km' => 'SU99A', 'recorded_by' => 'Tester', 'identification_verification_status' => 'V', 'data_source_id' => 1, 'blocked' => 1, 'deleted_at' => null],
            ['id' => 5, 'unique_key' => 'TEST:5', 'taxon_id' => 2, 'taxon_name_id' => 2, 'grid_ref' => 'SU99A1234', 'grid_ref_2km' => 'SU99A', 'recorded_by' => 'Tester', 'identification_verification_status' => 'V', 'data_source_id' => 1, 'blocked' => 0, 'deleted_at' => '2026-07-01 00:00:00'],
            ['id' => 6, 'unique_key' => 'TEST:6', 'taxon_id' => 1, 'taxon_name_id' => 1, 'grid_ref' => 'SU10B1234', 'grid_ref_2km' => 'SU10B', 'recorded_by' => 'Tester', 'identification_verification_status' => 'V', 'data_source_id' => 1, 'blocked' => 0, 'deleted_at' => null],
        ]);

        $this->db->table('geographic_regions_occurrences')->insertBatch([
            ['geographic_region_id' => 11, 'occurrence_id' => 1],
            ['geographic_region_id' => 11, 'occurrence_id' => 2],
            ['geographic_region_id' => 11, 'occurrence_id' => 3],
            ['geographic_region_id' => 11, 'occurrence_id' => 4],
            ['geographic_region_id' => 11, 'occurrence_id' => 5],
            ['geographic_region_id' => 22, 'occurrence_id' => 6],
        ]);

        $service = new GridSquareStatsCountsService();
        $counts = $service->run(false);

        $this->assertSame('success', $counts['status']);
        $this->assertSame(2, $counts['fetched']);
        $this->assertSame(2, $counts['processed']);
        $this->assertSame(2, $counts['updated']);
        $this->assertSame(0, $counts['errors']);

        $su99a = $this->db->table('grid_square_stats')
            ->where('square', 'SU99A')
            ->where('geographic_region_id', 11)
            ->get()
            ->getRowArray();

        $su10b = $this->db->table('grid_square_stats')
            ->where('square', 'SU10B')
            ->where('geographic_region_id', 22)
            ->get()
            ->getRowArray();

        $this->assertNotNull($su99a);
        $this->assertSame(3, (int) $su99a['occurrences_count']);
        $this->assertSame(2, (int) $su99a['species_count']);

        $this->assertNotNull($su10b);
        $this->assertSame(1, (int) $su10b['occurrences_count']);
        $this->assertSame(1, (int) $su10b['species_count']);
    }

    public function testRunResetsCountsAndSkipsUnmatchedGridSquareRows(): void
    {
        $this->seedTaxa();
        $this->seedDataSource();
        $this->seedTaxonNames();
        $this->seedGeographicRegions();
        $this->seedGridSquareStats();

        $this->db->table('grid_square_stats')
            ->where('square', 'SU10B')
            ->where('geographic_region_id', 22)
            ->update([
                'occurrences_count' => 9,
                'species_count' => 7,
            ]);

        $this->db->table('occurrences')->insertBatch([
            ['id' => 101, 'unique_key' => 'TEST:101', 'taxon_id' => 1, 'taxon_name_id' => 1, 'grid_ref' => 'SU99A1234', 'grid_ref_2km' => 'SU99A', 'recorded_by' => 'Tester', 'identification_verification_status' => 'V', 'data_source_id' => 1, 'blocked' => 0, 'deleted_at' => null],
            ['id' => 102, 'unique_key' => 'TEST:102', 'taxon_id' => 2, 'taxon_name_id' => 2, 'grid_ref' => 'SU77C1234', 'grid_ref_2km' => 'SU77C', 'recorded_by' => 'Tester', 'identification_verification_status' => 'V', 'data_source_id' => 1, 'blocked' => 0, 'deleted_at' => null],
        ]);

        $this->db->table('geographic_regions_occurrences')->insertBatch([
            ['geographic_region_id' => 11, 'occurrence_id' => 101],
            ['geographic_region_id' => 33, 'occurrence_id' => 102],
        ]);

        $service = new GridSquareStatsCountsService();
        $counts = $service->run(false);

        $this->assertSame('success', $counts['status']);
        $this->assertSame(2, $counts['fetched']);
        $this->assertSame(2, $counts['processed']);
        $this->assertSame(1, $counts['updated']);
        $this->assertSame(1, $counts['skipped']);

        $su99a = $this->db->table('grid_square_stats')
            ->where('square', 'SU99A')
            ->where('geographic_region_id', 11)
            ->get()
            ->getRowArray();

        $su10b = $this->db->table('grid_square_stats')
            ->where('square', 'SU10B')
            ->where('geographic_region_id', 22)
            ->get()
            ->getRowArray();

        $this->assertNotNull($su99a);
        $this->assertSame(1, (int) $su99a['occurrences_count']);
        $this->assertSame(1, (int) $su99a['species_count']);

        $this->assertNotNull($su10b);
        $this->assertSame(0, (int) $su10b['occurrences_count']);
        $this->assertSame(0, (int) $su10b['species_count']);
    }

    private function seedTaxa(): void
    {
        $this->db->table('taxon_groups')->insert([
            'id' => 1,
            'title' => 'Test group',
            'indicia_taxon_group_id' => 1,
            'implied' => 0,
            'deleted_at' => null,
        ]);

        $this->db->table('taxon_ranks')->insert([
            'id' => 1,
            'rank' => 'Species',
            'abbr' => 'sp',
            'sort_order' => 1,
            'deleted_at' => null,
        ]);

        $this->db->table('taxa')->insertBatch([
            [
                'id' => 1,
                'taxon_identifier' => 'TX-1',
                'scientific_name_identifier' => 'SCI-1',
                'scientific_name' => 'Species one',
                'vernacular_name' => 'Species one',
                'taxon_rank_id' => 1,
                'taxon_group_id' => 1,
                'rarity_group_name' => 'common',
                'species_id' => 1,
                'blocked' => 0,
                'blocked_reason' => null,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'taxon_identifier' => 'TX-2',
                'scientific_name_identifier' => 'SCI-2',
                'scientific_name' => 'Species two',
                'vernacular_name' => 'Species two',
                'taxon_rank_id' => 1,
                'taxon_group_id' => 1,
                'rarity_group_name' => 'common',
                'species_id' => 2,
                'blocked' => 0,
                'blocked_reason' => null,
                'deleted_at' => null,
            ],
            [
                'id' => 3,
                'taxon_identifier' => 'TX-3',
                'scientific_name_identifier' => 'SCI-3',
                'scientific_name' => 'Species one variant',
                'vernacular_name' => 'Species one variant',
                'taxon_rank_id' => 1,
                'taxon_group_id' => 1,
                'rarity_group_name' => 'common',
                'species_id' => 1,
                'blocked' => 0,
                'blocked_reason' => null,
                'deleted_at' => null,
            ],
        ]);
    }

    private function seedDataSource(): void
    {
        $this->db->table('data_sources')->insert([
            'id' => 1,
            'abbr' => 'TST',
            'title' => 'Test source',
            'url' => 'https://example.org',
        ]);
    }

    private function seedTaxonNames(): void
    {
        $this->db->table('taxon_names')->insertBatch([
            [
                'id' => 1,
                'uuid' => '11111111-1111-4111-8111-111111111111',
                'taxon_id' => 1,
                'name' => 'Species one',
                'given_name_identifier' => 'GN-1',
                'accepted' => 1,
                'scientific' => 1,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'uuid' => '22222222-2222-4222-8222-222222222222',
                'taxon_id' => 2,
                'name' => 'Species two',
                'given_name_identifier' => 'GN-2',
                'accepted' => 1,
                'scientific' => 1,
                'deleted_at' => null,
            ],
            [
                'id' => 3,
                'uuid' => '33333333-3333-4333-8333-333333333333',
                'taxon_id' => 3,
                'name' => 'Species one variant',
                'given_name_identifier' => 'GN-3',
                'accepted' => 1,
                'scientific' => 1,
                'deleted_at' => null,
            ],
        ]);
    }

    private function seedGridSquareStats(): void
    {
        $this->db->table('grid_square_stats')->insertBatch([
            ['id' => 1, 'uuid' => 'aaaa1111-1111-4111-8111-111111111111', 'square' => 'SU99A', 'geographic_region_id' => 11, 'easting' => 100000, 'northing' => 200000, 'lon' => '-1.0000000', 'lat' => '53.0000000', 'partial' => 0, 'occurrences_count' => 0, 'species_count' => 0],
            ['id' => 2, 'uuid' => 'bbbb2222-2222-4222-8222-222222222222', 'square' => 'SU10B', 'geographic_region_id' => 22, 'easting' => 101000, 'northing' => 201000, 'lon' => '-1.0100000', 'lat' => '53.0100000', 'partial' => 0, 'occurrences_count' => 0, 'species_count' => 0],
        ]);
    }

    private function seedGeographicRegions(): void
    {
        $this->db->table('geographic_regions')->insertBatch([
            [
                'id' => 11,
                'higher_geography_identifier' => 10011,
                'higher_geography' => 'Region 11',
                'location_type' => 'Vice County',
                'data_source_id' => 1,
                'deleted_at' => null,
            ],
            [
                'id' => 22,
                'higher_geography_identifier' => 10022,
                'higher_geography' => 'Region 22',
                'location_type' => 'Vice County',
                'data_source_id' => 1,
                'deleted_at' => null,
            ],
            [
                'id' => 33,
                'higher_geography_identifier' => 10033,
                'higher_geography' => 'Region 33',
                'location_type' => 'Vice County',
                'data_source_id' => 1,
                'deleted_at' => null,
            ],
        ]);
    }
}
