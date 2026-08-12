<?php

namespace Tests;

use App\Services\Stats\TaxonStatsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TaxonStatsServiceTest extends CIUnitTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = db_connect();
        $prefix = $this->db->getPrefix();

        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxon_stats');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'geographic_regions_occurrences');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'occurrences');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxa');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'geographic_regions');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            taxon_identifier VARCHAR(100) NOT NULL,
            is_reporting INTEGER NOT NULL DEFAULT 1,
            order_id INTEGER NULL,
            superfamily_id INTEGER NULL,
            family_id INTEGER NULL,
            genus_id INTEGER NULL,
            species_id INTEGER NULL,
            blocked INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'geographic_regions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            higher_geography_identifier INTEGER NOT NULL,
            higher_geography VARCHAR(100) NOT NULL,
            location_type VARCHAR(100) NOT NULL,
            data_source_id INTEGER NOT NULL,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'occurrences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            taxon_id INTEGER NOT NULL,
            order_id INTEGER NULL,
            superfamily_id INTEGER NULL,
            family_id INTEGER NULL,
            genus_id INTEGER NULL,
            species_id INTEGER NULL,
            from_date DATE NULL,
            to_date DATE NULL,
            grid_ref_2km VARCHAR(5) NULL,
            recorded_by VARCHAR(255) NULL,
            identification_verification_status VARCHAR(8) NULL,
            blocked INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'geographic_regions_occurrences (
            geographic_region_id INTEGER NOT NULL,
            occurrence_id INTEGER NOT NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxon_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid CHAR(36) NOT NULL,
            taxon_id INTEGER NOT NULL,
            geographic_region_id INTEGER NULL,
            occurrences_count INTEGER NOT NULL DEFAULT 0,
            grid_square_count INTEGER NOT NULL DEFAULT 0,
            first_record_date DATE NOT NULL,
            last_record_date DATE NOT NULL,
            first_recorder VARCHAR(255) NOT NULL,
            last_recorder VARCHAR(255) NOT NULL,
            first_verified_record_date DATE NOT NULL,
            last_verified_record_date DATE NOT NULL,
            first_verified_recorder VARCHAR(255) NOT NULL,
            last_verified_recorder VARCHAR(255) NOT NULL
        )');

        foreach ([
            ['id' => 1, 'taxon_identifier' => 'TX-1', 'is_reporting' => 0, 'order_id' => 1, 'superfamily_id' => 1, 'family_id' => 1, 'genus_id' => 1, 'species_id' => 1, 'blocked' => 0, 'deleted_at' => null],
            ['id' => 2, 'taxon_identifier' => 'TX-2', 'is_reporting' => 0, 'order_id' => 2, 'superfamily_id' => 2, 'family_id' => 2, 'genus_id' => 2, 'species_id' => 2, 'blocked' => 0, 'deleted_at' => null],
            ['id' => 3, 'taxon_identifier' => 'TX-3', 'blocked' => 1, 'deleted_at' => null],
        ] as $taxon) {
            $this->db->table('taxa')->insert($taxon);
        }

        $this->db->table('geographic_regions')->insertBatch([
            ['id' => 11, 'higher_geography_identifier' => 11, 'higher_geography' => 'Region 11', 'location_type' => 'VC', 'data_source_id' => 1, 'deleted_at' => null],
            ['id' => 22, 'higher_geography_identifier' => 22, 'higher_geography' => 'Region 22', 'location_type' => 'VC', 'data_source_id' => 1, 'deleted_at' => null],
        ]);
    }

    public function testRunBuildsGlobalAndRegionalRowsAndFiltersInactiveOccurrences(): void
    {
        $this->db->table('occurrences')->insertBatch([
            ['id' => 1, 'taxon_id' => 1, 'from_date' => '2020-01-01', 'to_date' => null, 'grid_ref_2km' => 'SU01A', 'recorded_by' => 'First', 'identification_verification_status' => 'V', 'blocked' => 0, 'deleted_at' => null],
            ['id' => 2, 'taxon_id' => 1, 'from_date' => null, 'to_date' => '2020-02-01', 'grid_ref_2km' => 'SU01A', 'recorded_by' => 'Second', 'identification_verification_status' => 'C', 'blocked' => 0, 'deleted_at' => null],
            ['id' => 3, 'taxon_id' => 1, 'from_date' => '2021-01-01', 'to_date' => null, 'grid_ref_2km' => 'SU02B', 'recorded_by' => 'Third', 'identification_verification_status' => 'V2', 'blocked' => 0, 'deleted_at' => null],
            ['id' => 4, 'taxon_id' => 2, 'from_date' => '2022-01-01', 'to_date' => null, 'grid_ref_2km' => 'SU03C', 'recorded_by' => 'Other', 'identification_verification_status' => 'V1', 'blocked' => 0, 'deleted_at' => null],
            ['id' => 5, 'taxon_id' => 1, 'from_date' => '2023-01-01', 'to_date' => null, 'grid_ref_2km' => 'SU04D', 'recorded_by' => 'Blocked', 'identification_verification_status' => 'V', 'blocked' => 1, 'deleted_at' => null],
            ['id' => 6, 'taxon_id' => 1, 'from_date' => '2023-02-01', 'to_date' => null, 'grid_ref_2km' => 'SU05E', 'recorded_by' => 'Deleted', 'identification_verification_status' => 'V', 'blocked' => 0, 'deleted_at' => '2026-07-01 00:00:00'],
            ['id' => 7, 'taxon_id' => 3, 'from_date' => '2021-05-01', 'to_date' => null, 'grid_ref_2km' => 'SU06F', 'recorded_by' => 'Taxon blocked', 'identification_verification_status' => 'V', 'blocked' => 0, 'deleted_at' => null],
        ]);

        $this->db->table('geographic_regions_occurrences')->insertBatch([
            ['geographic_region_id' => 11, 'occurrence_id' => 1],
            ['geographic_region_id' => 11, 'occurrence_id' => 2],
            ['geographic_region_id' => 22, 'occurrence_id' => 3],
            ['geographic_region_id' => 11, 'occurrence_id' => 4],
            ['geographic_region_id' => 11, 'occurrence_id' => 5],
            ['geographic_region_id' => 11, 'occurrence_id' => 6],
            ['geographic_region_id' => 11, 'occurrence_id' => 7],
        ]);

        $service = new TaxonStatsService();
        $counts = $service->run(false);

        $this->assertSame('success', $counts['status']);
        $this->assertSame(5, $counts['inserted']);

        $globalTaxon1 = $this->findTaxonStatRow(1, null);
        $region11Taxon1 = $this->findTaxonStatRow(1, 11);
        $region22Taxon1 = $this->findTaxonStatRow(1, 22);
        $globalTaxon2 = $this->findTaxonStatRow(2, null);
        $region11Taxon2 = $this->findTaxonStatRow(2, 11);

        $this->assertNotNull($globalTaxon1);
        $this->assertSame(3, (int) $globalTaxon1['occurrences_count']);
        $this->assertSame(2, (int) $globalTaxon1['grid_square_count']);
        $this->assertSame('2020-01-01', (string) $globalTaxon1['first_record_date']);
        $this->assertSame('2021-01-01', (string) $globalTaxon1['last_record_date']);
        $this->assertSame('First', (string) $globalTaxon1['first_recorder']);
        $this->assertSame('Third', (string) $globalTaxon1['last_recorder']);
        $this->assertSame('2020-01-01', (string) $globalTaxon1['first_verified_record_date']);
        $this->assertSame('2021-01-01', (string) $globalTaxon1['last_verified_record_date']);

        $this->assertNotNull($region11Taxon1);
        $this->assertSame(2, (int) $region11Taxon1['occurrences_count']);
        $this->assertSame(1, (int) $region11Taxon1['grid_square_count']);
        $this->assertSame('2020-01-01', (string) $region11Taxon1['first_record_date']);
        $this->assertSame('2020-02-01', (string) $region11Taxon1['last_record_date']);
        $this->assertSame('2020-01-01', (string) $region11Taxon1['first_verified_record_date']);
        $this->assertSame('2020-01-01', (string) $region11Taxon1['last_verified_record_date']);

        $this->assertNotNull($region22Taxon1);
        $this->assertSame(1, (int) $region22Taxon1['occurrences_count']);
        $this->assertSame(1, (int) $region22Taxon1['grid_square_count']);
        $this->assertSame('2021-01-01', (string) $region22Taxon1['first_verified_record_date']);
        $this->assertSame('Third', (string) $region22Taxon1['first_verified_recorder']);

        $this->assertNotNull($globalTaxon2);
        $this->assertNotNull($region11Taxon2);

        $allRows = $this->db->table('taxon_stats')->get()->getResultArray();
        $this->assertCount(5, $allRows);

        foreach ($allRows as $row) {
            $this->assertSame(36, strlen((string) $row['uuid']));
        }
    }

    public function testRunDryRunDoesNotPersistChanges(): void
    {
        $this->db->table('occurrences')->insert([
            'id' => 100,
            'taxon_id' => 1,
            'from_date' => '2020-01-01',
            'to_date' => null,
            'grid_ref_2km' => 'SU01A',
            'recorded_by' => 'Dry',
            'identification_verification_status' => 'V',
            'blocked' => 0,
            'deleted_at' => null,
        ]);

        $this->db->table('geographic_regions_occurrences')->insert([
            'geographic_region_id' => 11,
            'occurrence_id' => 100,
        ]);

        $service = new TaxonStatsService();
        $counts = $service->run(true);

        $this->assertSame('success', $counts['status']);
        $this->assertGreaterThan(0, (int) $counts['fetched']);
        $this->assertSame(0, $this->db->table('taxon_stats')->countAllResults());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTaxonStatRow(int $taxonId, ?int $geographicRegionId): ?array
    {
        $builder = $this->db->table('taxon_stats')->where('taxon_id', $taxonId);

        if ($geographicRegionId === null) {
            $builder->where('geographic_region_id', null);
        } else {
            $builder->where('geographic_region_id', $geographicRegionId);
        }

        $row = $builder->get()->getRowArray();

        return is_array($row) ? $row : null;
    }
}
