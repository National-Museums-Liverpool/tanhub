<?php

namespace Tests;

use App\Services\Stats\TaxonYearStatsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TaxonYearStatsServiceTest extends CIUnitTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = db_connect();
        $prefix = $this->db->getPrefix();

        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxon_year_stats');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'geographic_regions_occurrences');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'occurrences');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxa');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            taxon_identifier VARCHAR(100) NOT NULL,
            blocked INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'occurrences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            taxon_id INTEGER NOT NULL,
            from_date DATE NULL,
            to_date DATE NULL,
            grid_ref_2km VARCHAR(5) NULL,
            blocked INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'geographic_regions_occurrences (
            geographic_region_id INTEGER NOT NULL,
            occurrence_id INTEGER NOT NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxon_year_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid CHAR(36) NOT NULL,
            taxon_id INTEGER NOT NULL,
            geographic_region_id INTEGER NULL,
            year INTEGER NOT NULL,
            occurrences_count INTEGER NOT NULL DEFAULT 0,
            grid_square_count INTEGER NOT NULL DEFAULT 0
        )');

        $this->db->table('taxa')->insertBatch([
            ['id' => 1, 'taxon_identifier' => 'TX-1', 'blocked' => 0, 'deleted_at' => null],
            ['id' => 2, 'taxon_identifier' => 'TX-2', 'blocked' => 0, 'deleted_at' => null],
            ['id' => 3, 'taxon_identifier' => 'TX-3', 'blocked' => 1, 'deleted_at' => null],
        ]);
    }

    public function testRunBuildsGlobalAndRegionalRowsWithinRollingTenYears(): void
    {
        $currentYear = (int) date('Y');
        $withinWindowYear = $currentYear - 2;
        $outsideWindowYear = $currentYear - 11;

        $this->db->table('occurrences')->insertBatch([
            ['id' => 1, 'taxon_id' => 1, 'from_date' => $withinWindowYear . '-01-01', 'to_date' => null, 'grid_ref_2km' => 'SU01A', 'blocked' => 0, 'deleted_at' => null],
            ['id' => 2, 'taxon_id' => 1, 'from_date' => null, 'to_date' => $withinWindowYear . '-02-01', 'grid_ref_2km' => 'SU01A', 'blocked' => 0, 'deleted_at' => null],
            ['id' => 3, 'taxon_id' => 1, 'from_date' => $withinWindowYear . '-05-01', 'to_date' => null, 'grid_ref_2km' => 'SU02B', 'blocked' => 0, 'deleted_at' => null],
            ['id' => 4, 'taxon_id' => 2, 'from_date' => $withinWindowYear . '-03-15', 'to_date' => null, 'grid_ref_2km' => 'SU03C', 'blocked' => 0, 'deleted_at' => null],
            ['id' => 5, 'taxon_id' => 1, 'from_date' => $outsideWindowYear . '-01-01', 'to_date' => null, 'grid_ref_2km' => 'SU09Z', 'blocked' => 0, 'deleted_at' => null],
            ['id' => 6, 'taxon_id' => 1, 'from_date' => $withinWindowYear . '-06-01', 'to_date' => null, 'grid_ref_2km' => 'SU08Y', 'blocked' => 1, 'deleted_at' => null],
            ['id' => 7, 'taxon_id' => 3, 'from_date' => $withinWindowYear . '-07-01', 'to_date' => null, 'grid_ref_2km' => 'SU07X', 'blocked' => 0, 'deleted_at' => null],
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

        $service = new TaxonYearStatsService();
        $counts = $service->run(false);

        $this->assertSame('success', $counts['status']);
        $this->assertSame(5, (int) $counts['inserted']);

        $globalTaxon1 = $this->findTaxonYearStatRow(1, null, $withinWindowYear);
        $region11Taxon1 = $this->findTaxonYearStatRow(1, 11, $withinWindowYear);
        $region22Taxon1 = $this->findTaxonYearStatRow(1, 22, $withinWindowYear);
        $globalTaxon2 = $this->findTaxonYearStatRow(2, null, $withinWindowYear);
        $region11Taxon2 = $this->findTaxonYearStatRow(2, 11, $withinWindowYear);

        $this->assertNotNull($globalTaxon1);
        $this->assertSame(3, (int) $globalTaxon1['occurrences_count']);
        $this->assertSame(2, (int) $globalTaxon1['grid_square_count']);

        $this->assertNotNull($region11Taxon1);
        $this->assertSame(2, (int) $region11Taxon1['occurrences_count']);
        $this->assertSame(1, (int) $region11Taxon1['grid_square_count']);

        $this->assertNotNull($region22Taxon1);
        $this->assertSame(1, (int) $region22Taxon1['occurrences_count']);
        $this->assertSame(1, (int) $region22Taxon1['grid_square_count']);

        $this->assertNotNull($globalTaxon2);
        $this->assertSame(1, (int) $globalTaxon2['occurrences_count']);

        $this->assertNotNull($region11Taxon2);
        $this->assertSame(1, (int) $region11Taxon2['occurrences_count']);

        $outsideWindow = $this->findTaxonYearStatRow(1, 11, $outsideWindowYear);
        $this->assertNull($outsideWindow);

        foreach ($this->db->table('taxon_year_stats')->get()->getResultArray() as $row) {
            $this->assertSame(36, strlen((string) $row['uuid']));
        }
    }

    public function testRunDryRunDoesNotPersistChanges(): void
    {
        $currentYear = (int) date('Y');

        $this->db->table('occurrences')->insert([
            'id' => 100,
            'taxon_id' => 1,
            'from_date' => $currentYear . '-01-01',
            'to_date' => null,
            'grid_ref_2km' => 'SU01A',
            'blocked' => 0,
            'deleted_at' => null,
        ]);

        $this->db->table('geographic_regions_occurrences')->insert([
            'geographic_region_id' => 11,
            'occurrence_id' => 100,
        ]);

        $service = new TaxonYearStatsService();
        $counts = $service->run(true);

        $this->assertSame('success', $counts['status']);
        $this->assertGreaterThan(0, (int) $counts['fetched']);
        $this->assertSame(0, $this->db->table('taxon_year_stats')->countAllResults());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTaxonYearStatRow(int $taxonId, ?int $geographicRegionId, int $year): ?array
    {
        $builder = $this->db->table('taxon_year_stats')
            ->where('taxon_id', $taxonId)
            ->where('year', $year);

        if ($geographicRegionId === null) {
            $builder->where('geographic_region_id', null);
        } else {
            $builder->where('geographic_region_id', $geographicRegionId);
        }

        $row = $builder->get()->getRowArray();

        return is_array($row) ? $row : null;
    }
}
