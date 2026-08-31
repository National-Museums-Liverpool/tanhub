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
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'import_offsets');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxon_year_stats');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'geographic_regions_occurrences');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'occurrences');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxa');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxon_ranks');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'geographic_regions');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxon_ranks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            is_reporting INTEGER NOT NULL DEFAULT 0
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            taxon_identifier VARCHAR(100) NOT NULL,
            taxon_rank_id INTEGER NOT NULL,
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
            frequency_trend INTEGER NULL DEFAULT NULL,
            frequency_trend_state VARCHAR(32) NULL DEFAULT NULL,
            first_record_date DATE NOT NULL,
            last_record_date DATE NOT NULL,
            first_recorder VARCHAR(255) NOT NULL,
            last_recorder VARCHAR(255) NOT NULL,
            first_verified_record_date DATE NOT NULL,
            last_verified_record_date DATE NOT NULL,
            first_verified_recorder VARCHAR(255) NOT NULL,
            last_verified_recorder VARCHAR(255) NOT NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'import_offsets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source_key VARCHAR(64) NOT NULL UNIQUE,
            next_offset INTEGER NOT NULL DEFAULT 0,
            next_checkpoint VARCHAR(255) NULL,
            is_complete INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NULL,
            updated_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxon_year_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            taxon_id INTEGER NOT NULL,
            geographic_region_id INTEGER NULL,
            year INTEGER NOT NULL,
            occurrences_count INTEGER NOT NULL DEFAULT 0,
            grid_square_count INTEGER NOT NULL DEFAULT 0
        )');

        foreach ([
            ['id' => 1, 'is_reporting' => 0],
            ['id' => 2, 'is_reporting' => 1],
        ] as $rank) {
            $this->db->table('taxon_ranks')->insert($rank);
        }

        foreach ([
            ['id' => 1, 'taxon_identifier' => 'TX-1', 'taxon_rank_id' => 1, 'order_id' => 1, 'superfamily_id' => 1, 'family_id' => 1, 'genus_id' => 1, 'species_id' => 1, 'blocked' => 0, 'deleted_at' => null],
            ['id' => 2, 'taxon_identifier' => 'TX-2', 'taxon_rank_id' => 1, 'order_id' => 2, 'superfamily_id' => 2, 'family_id' => 2, 'genus_id' => 2, 'species_id' => 2, 'blocked' => 0, 'deleted_at' => null],
            ['id' => 3, 'taxon_identifier' => 'TX-3', 'taxon_rank_id' => 1, 'blocked' => 1, 'deleted_at' => null],
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
        $counts = $this->runAllTaxonStatBatches($service);

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

    public function testRunCalculatesIncreasingAndDecreasingFrequencyTrends(): void
    {
        $currentYear = (int) date('Y');
        $years = range($currentYear - 9, $currentYear - 1);
        $noisySeries = [8, 0, 0, 8, 0, 0, 8, 0, 1];

        $this->db->table('taxa')->insertBatch([
            ['id' => 4, 'taxon_identifier' => 'TX-4', 'taxon_rank_id' => 1, 'order_id' => 4, 'superfamily_id' => 4, 'family_id' => 4, 'genus_id' => 4, 'species_id' => 4, 'blocked' => 0, 'deleted_at' => null],
            ['id' => 5, 'taxon_identifier' => 'TX-5', 'taxon_rank_id' => 1, 'order_id' => 5, 'superfamily_id' => 5, 'family_id' => 5, 'genus_id' => 5, 'species_id' => 5, 'blocked' => 0, 'deleted_at' => null],
            ['id' => 6, 'taxon_identifier' => 'TX-6', 'taxon_rank_id' => 1, 'order_id' => 6, 'superfamily_id' => 6, 'family_id' => 6, 'genus_id' => 6, 'species_id' => 6, 'blocked' => 0, 'deleted_at' => null],
        ]);
        $this->db->table('taxa')->whereIn('id', [1, 2, 4, 5, 6])->update(['taxon_rank_id' => 2]);

        $this->db->table('occurrences')->insertBatch([
            ['id' => 30, 'taxon_id' => 1, 'from_date' => ($currentYear - 1) . '-01-01', 'to_date' => null, 'grid_ref_2km' => 'SU30A', 'recorded_by' => 'Increasing', 'identification_verification_status' => 'V', 'blocked' => 0, 'deleted_at' => null],
            ['id' => 31, 'taxon_id' => 2, 'from_date' => ($currentYear - 1) . '-01-01', 'to_date' => null, 'grid_ref_2km' => 'SU31A', 'recorded_by' => 'Decreasing', 'identification_verification_status' => 'V', 'blocked' => 0, 'deleted_at' => null],
            ['id' => 32, 'taxon_id' => 4, 'from_date' => ($currentYear - 1) . '-01-01', 'to_date' => null, 'grid_ref_2km' => 'SU32A', 'recorded_by' => 'Stable', 'identification_verification_status' => 'V', 'blocked' => 0, 'deleted_at' => null],
            ['id' => 33, 'taxon_id' => 5, 'from_date' => ($currentYear - 1) . '-01-01', 'to_date' => null, 'grid_ref_2km' => 'SU33A', 'recorded_by' => 'Sparse', 'identification_verification_status' => 'V', 'blocked' => 0, 'deleted_at' => null],
            ['id' => 34, 'taxon_id' => 6, 'from_date' => ($currentYear - 1) . '-01-01', 'to_date' => null, 'grid_ref_2km' => 'SU34A', 'recorded_by' => 'Noisy', 'identification_verification_status' => 'V', 'blocked' => 0, 'deleted_at' => null],
        ]);
        $this->db->table('occurrences')->set('species_id', 'taxon_id', false)->where('id >=', 30)->update();
        $this->db->table('geographic_regions_occurrences')->insertBatch([
            ['geographic_region_id' => 11, 'occurrence_id' => 30],
            ['geographic_region_id' => 11, 'occurrence_id' => 31],
            ['geographic_region_id' => 11, 'occurrence_id' => 32],
            ['geographic_region_id' => 11, 'occurrence_id' => 33],
            ['geographic_region_id' => 11, 'occurrence_id' => 34],
        ]);

        foreach ($years as $index => $year) {
            $this->db->table('taxon_year_stats')->insertBatch([
                ['taxon_id' => 1, 'geographic_region_id' => null, 'year' => $year, 'occurrences_count' => $index + 1, 'grid_square_count' => $index + 1],
                ['taxon_id' => 1, 'geographic_region_id' => 11, 'year' => $year, 'occurrences_count' => $index + 1, 'grid_square_count' => $index + 1],
                ['taxon_id' => 2, 'geographic_region_id' => null, 'year' => $year, 'occurrences_count' => 9 - $index, 'grid_square_count' => 9 - $index],
                ['taxon_id' => 2, 'geographic_region_id' => 11, 'year' => $year, 'occurrences_count' => 9 - $index, 'grid_square_count' => 9 - $index],
                ['taxon_id' => 4, 'geographic_region_id' => null, 'year' => $year, 'occurrences_count' => 5, 'grid_square_count' => 5],
                ['taxon_id' => 4, 'geographic_region_id' => 11, 'year' => $year, 'occurrences_count' => 5, 'grid_square_count' => 5],
                ['taxon_id' => 5, 'geographic_region_id' => null, 'year' => $year, 'occurrences_count' => $index === 5 ? 4 : 0, 'grid_square_count' => $index === 5 ? 1 : 0],
                ['taxon_id' => 5, 'geographic_region_id' => 11, 'year' => $year, 'occurrences_count' => $index === 5 ? 4 : 0, 'grid_square_count' => $index === 5 ? 1 : 0],
                ['taxon_id' => 6, 'geographic_region_id' => null, 'year' => $year, 'occurrences_count' => $noisySeries[$index], 'grid_square_count' => $noisySeries[$index]],
                ['taxon_id' => 6, 'geographic_region_id' => 11, 'year' => $year, 'occurrences_count' => $noisySeries[$index], 'grid_square_count' => $noisySeries[$index]],
            ]);
        }

        $counts = $this->runAllTaxonStatBatches(new TaxonStatsService());

        $this->assertSame('success', $counts['status']);
        $this->assertSame(100, (int) $this->findTaxonStatRow(1, null)['frequency_trend']);
        $this->assertSame(0, (int) $this->findTaxonStatRow(2, null)['frequency_trend']);
        $this->assertSame(100, (int) $this->findTaxonStatRow(1, 11)['frequency_trend']);
        $this->assertSame(0, (int) $this->findTaxonStatRow(2, 11)['frequency_trend']);
        $this->assertSame(50, (int) $this->findTaxonStatRow(4, null)['frequency_trend']);
        $this->assertSame('stable', $this->findTaxonStatRow(4, null)['frequency_trend_state']);
        $this->assertSame(50, (int) $this->findTaxonStatRow(4, 11)['frequency_trend']);
        $this->assertSame('stable', $this->findTaxonStatRow(4, 11)['frequency_trend_state']);
        $this->assertNull($this->findTaxonStatRow(5, null)['frequency_trend']);
        $this->assertSame('insufficient_data', $this->findTaxonStatRow(5, null)['frequency_trend_state']);
        $this->assertNull($this->findTaxonStatRow(5, 11)['frequency_trend']);
        $this->assertSame('insufficient_data', $this->findTaxonStatRow(5, 11)['frequency_trend_state']);
        $this->assertNull($this->findTaxonStatRow(6, null)['frequency_trend']);
        $this->assertSame('unclear', $this->findTaxonStatRow(6, null)['frequency_trend_state']);
        $this->assertNull($this->findTaxonStatRow(6, 11)['frequency_trend']);
        $this->assertSame('unclear', $this->findTaxonStatRow(6, 11)['frequency_trend_state']);
    }

    /**
     * Verify that recent years have more influence when configured.
     */
    public function testRegressionWeightsRecentYears(): void
    {
        $service = new TaxonStatsService();
        $reflection = new \ReflectionClass($service);
        $regression = $reflection->getMethod('regression');
        $regression->setAccessible(true);
        $series = array_combine(range(2016, 2025), [20, 18, 16, 14, 12, 10, 8, 6, 4, 20]);

        $unweighted = $regression->invoke($service, $series, 0.0);
        $weighted = $regression->invoke($service, $series, 3.0);

        $this->assertGreaterThan($unweighted['slope'], $weighted['slope']);
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
        $this->db->table('import_offsets')->insert([
            'source_key' => 'derived-stats:taxon_stats',
            'next_checkpoint' => '5',
            'is_complete' => 0,
        ]);

        $service = new TaxonStatsService();
        $counts = $service->run(true);

        $this->assertSame('success', $counts['status']);
        $this->assertGreaterThan(0, (int) $counts['fetched']);
        $this->assertSame(0, $this->db->table('taxon_stats')->countAllResults());
    }

    /**
     * Ensure non-reporting taxa contribute to each configured reporting projection.
     */
    public function testRunRollsUpNonReportingTaxonToConfiguredProjections(): void
    {
        $this->db->table('taxa')->insert([
            'id' => 4,
            'taxon_identifier' => 'SUBSPECIES',
            'taxon_rank_id' => 1,
            'order_id' => 10,
            'superfamily_id' => 11,
            'family_id' => 12,
            'genus_id' => 13,
            'species_id' => 14,
            'blocked' => 0,
            'deleted_at' => null,
        ]);
        $this->db->table('occurrences')->insert([
            'id' => 20,
            'taxon_id' => 4,
            'order_id' => 10,
            'superfamily_id' => 11,
            'family_id' => 12,
            'genus_id' => 13,
            'species_id' => 14,
            'from_date' => '2024-06-01',
            'grid_ref_2km' => 'SU20A',
            'recorded_by' => 'Subspecies recorder',
            'identification_verification_status' => 'V',
            'blocked' => 0,
            'deleted_at' => null,
        ]);
        $this->db->table('geographic_regions_occurrences')->insert([
            'geographic_region_id' => 11,
            'occurrence_id' => 20,
        ]);

        $counts = $this->runAllTaxonStatBatches(new TaxonStatsService());

        $this->assertSame('success', $counts['status']);
        foreach ([4, 12, 14] as $taxonId) {
            $this->assertSame(1, (int) $this->findTaxonStatRow($taxonId, null)['occurrences_count']);
            $this->assertSame(1, (int) $this->findTaxonStatRow($taxonId, 11)['occurrences_count']);
        }
        $this->assertNull($this->findTaxonStatRow(4, null)['frequency_trend']);
        $this->assertNull($this->findTaxonStatRow(4, null)['frequency_trend_state']);
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

    /**
     * Run resumable taxon-stat batches until the configured ranks are complete.
     *
     * @param TaxonStatsService $service Taxon statistics service.
     *
     * @return array<string, int|string|bool> Final batch result.
     */
    private function runAllTaxonStatBatches(TaxonStatsService $service): array
    {
        $inserted = 0;
        do {
            $counts = $service->run(false);
            $inserted += (int) ($counts['inserted'] ?? 0);
        } while (($counts['has_more'] ?? false) === true);

        $counts['inserted'] = $inserted;

        return $counts;
    }
}
