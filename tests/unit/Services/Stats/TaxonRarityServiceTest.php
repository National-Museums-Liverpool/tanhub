<?php

namespace Tests;

use App\Services\Stats\TaxonRarityService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Rarity;

/**
 * @internal
 */
final class TaxonRarityServiceTest extends CIUnitTestCase
{
    protected $db;

    /**
     * Create isolated rarity tables and reset ranking weights.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->db = db_connect();
        $prefix = $this->db->getPrefix();

        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'occurrences');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxa');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            taxon_identifier VARCHAR(100) NOT NULL,
            species_id INTEGER NULL,
            rarity_group_name VARCHAR(100) NULL,
            rarity_category INTEGER NULL,
            exclude_from_rarity_calculations INTEGER NOT NULL DEFAULT 0,
            blocked INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'occurrences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            taxon_id INTEGER NOT NULL,
            species_id INTEGER NULL,
            grid_ref_2km VARCHAR(5) NULL,
            blocked INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $rarityConfig = config(Rarity::class);
        $rarityConfig->gridSquareWeight = 1.0;
        $rarityConfig->occurrenceWeight = 1.0;
    }

    /**
     * Recorded species are ranked independently within their rarity groups.
     */
    public function testRunAssignsCategoriesWithinEachRarityGroup(): void
    {
        $this->seedTaxa([
            [1, 'BEE-1', 'Bees', null],
            [2, 'BEE-2', 'Bees', null],
            [3, 'BEE-3', 'Bees', null],
            [4, 'BEE-4', 'Bees', null],
            [5, 'BEE-5', 'Bees', null],
            [6, 'BIRD-1', 'Birds', null],
            [7, 'BIRD-2', 'Birds', null],
            [8, 'BIRD-3', 'Birds', null],
            [9, 'BIRD-4', 'Birds', null],
            [10, 'BIRD-5', 'Birds', null],
        ]);

        $this->seedOccurrences([
            [1, 'SU00A', 0, null],
            [2, 'SU01A', 0, null],
            [2, 'SU01B', 0, null],
            [3, 'SU02A', 0, null],
            [3, 'SU02B', 0, null],
            [3, 'SU02C', 0, null],
            [4, 'SU03A', 0, null],
            [4, 'SU03B', 0, null],
            [4, 'SU03C', 0, null],
            [4, 'SU03D', 0, null],
            [5, 'SU04A', 0, null],
            [5, 'SU04B', 0, null],
            [5, 'SU04C', 0, null],
            [5, 'SU04D', 0, null],
            [5, 'SU04E', 0, null],
            [6, 'TV01A', 0, null],
            [6, 'TV01B', 0, null],
            [6, 'TV01C', 0, null],
            [6, 'TV01D', 0, null],
            [6, 'TV01E', 0, null],
            [6, 'TV01F', 0, null],
            [7, 'TV02A', 0, null],
            [7, 'TV02B', 0, null],
            [7, 'TV02C', 0, null],
            [7, 'TV02D', 0, null],
            [7, 'TV02E', 0, null],
            [7, 'TV02F', 0, null],
            [7, 'TV02G', 0, null],
            [8, 'TV03A', 0, null],
            [8, 'TV03B', 0, null],
            [8, 'TV03C', 0, null],
            [8, 'TV03D', 0, null],
            [8, 'TV03E', 0, null],
            [8, 'TV03F', 0, null],
            [8, 'TV03G', 0, null],
            [8, 'TV03H', 0, null],
            [9, 'TV04A', 0, null],
            [9, 'TV04B', 0, null],
            [9, 'TV04C', 0, null],
            [9, 'TV04D', 0, null],
            [9, 'TV04E', 0, null],
            [9, 'TV04F', 0, null],
            [9, 'TV04G', 0, null],
            [9, 'TV04H', 0, null],
            [9, 'TV04I', 0, null],
            [10, 'TV05A', 0, null],
            [10, 'TV05B', 0, null],
            [10, 'TV05C', 0, null],
            [10, 'TV05D', 0, null],
            [10, 'TV05E', 0, null],
            [10, 'TV05F', 0, null],
            [10, 'TV05G', 0, null],
            [10, 'TV05H', 0, null],
            [10, 'TV05I', 0, null],
            [10, 'TV05J', 0, null],
        ]);

        $service = new TaxonRarityService();
        $counts = $service->run(false);

        $this->assertSame('success', $counts['status']);
        $this->assertSame(10, $counts['fetched']);
        $this->assertSame(10, $counts['processed']);
        $this->assertSame(10, $counts['updated']);
        $this->assertSame(0, $counts['errors']);

        $this->assertSame([
            'BEE-1' => 1,
            'BEE-2' => 2,
            'BEE-3' => 3,
            'BEE-4' => 4,
            'BEE-5' => 5,
            'BIRD-1' => 1,
            'BIRD-2' => 2,
            'BIRD-3' => 3,
            'BIRD-4' => 4,
            'BIRD-5' => 5,
        ], $this->taxonCategoryMap());
    }

    public function testRunSupportsDryRunWithoutPersistingCategories(): void
    {
        $this->seedTaxa([
            [1, 'DRY-1', 'Dry run', 5],
            [2, 'DRY-2', 'Dry run', 1],
        ]);

        $this->seedOccurrences([
            [1, 'SU10A', 0, null],
            [2, 'SU10A', 0, null],
            [2, 'SU10B', 0, null],
        ]);

        $service = new TaxonRarityService();
        $counts = $service->run(true);

        $this->assertSame('success', $counts['status']);
        $this->assertSame(2, $counts['processed']);
        $this->assertSame(2, $counts['updated']);
        $this->assertSame([
            'DRY-1' => 5,
            'DRY-2' => 1,
        ], $this->taxonCategoryMap());
    }

    public function testRunUsesOccurrenceWeightAndIgnoresBlockedDeletedRows(): void
    {
        $rarityConfig = config(Rarity::class);
        $rarityConfig->gridSquareWeight = 0.0;
        $rarityConfig->occurrenceWeight = 1.0;

        $this->seedTaxa([
            [1, 'OCC-1', 'Occurrence weighted', null],
            [2, 'OCC-2', 'Occurrence weighted', null],
        ]);

        $this->seedOccurrences([
            [1, 'SU20A', 0, null],
            [1, 'SU20B', 1, null],
            [1, 'SU20C', 0, '2026-07-01 00:00:00'],
            [2, '', 0, null],
            [2, null, 0, null],
        ]);

        $service = new TaxonRarityService();
        $counts = $service->run(false);

        $this->assertSame('success', $counts['status']);
        $this->assertSame(2, $counts['processed']);
        $this->assertSame([
            'OCC-1' => 1,
            'OCC-2' => 5,
        ], $this->taxonCategoryMap());
    }

    /**
     * Equal metric counts share categories rather than being split by identifier.
     */
    public function testRunKeepsTiedSpeciesInTheSameCategory(): void
    {
        $taxa = [];
        $occurrences = [];

        for ($id = 1; $id <= 5; $id++) {
            $taxa[] = [$id, 'TIED-' . $id, 'Tied group', null];
            $occurrences[] = [$id, 'SU01A', 0, null];
        }

        $this->seedTaxa($taxa);
        $this->seedOccurrences($occurrences);

        $this->assertSame('success', (new TaxonRarityService())->run()['status']);
        $this->assertSame([
            'TIED-1' => 1,
            'TIED-2' => 1,
            'TIED-3' => 1,
            'TIED-4' => 1,
            'TIED-5' => 1,
        ], $this->taxonCategoryMap());
    }

    /**
     * Percentiles on either side of each 20-point boundary map to fixed bands.
     */
    public function testRunUsesEqualTwentyPointCategoryBands(): void
    {
        $taxa = [];
        $occurrences = [];

        for ($index = 0; $index <= 10; $index++) {
            $id = $index + 1;
            $taxa[] = [$id, 'BAND-' . $index, 'Bands', null];

            for ($square = 0; $square <= $index; $square++) {
                $occurrences[] = [$id, sprintf('SU%02dA', $square), 0, null];
            }
        }

        $this->seedTaxa($taxa);
        $this->seedOccurrences($occurrences);

        $config = config(Rarity::class);

        foreach ([[1.0, 1.0], [1.0, 6.0], [0.0, 2.0], [3.0, 0.0]] as [$squareWeight, $occurrenceWeight]) {
            $config->gridSquareWeight = $squareWeight;
            $config->occurrenceWeight = $occurrenceWeight;

            $this->assertSame('success', (new TaxonRarityService())->run()['status']);
            $this->assertSame([1, 1, 2, 2, 3, 3, 4, 4, 5, 5, 5], array_values($this->taxonCategoryMap()));
        }
    }

    /**
     * Weight ratios combine comparable percentiles without changing their scale.
     */
    public function testRunNormalizesUnequalWeights(): void
    {
        $config = config(Rarity::class);
        $config->gridSquareWeight = 2.0;
        $config->occurrenceWeight = 6.0;
        $taxa = [];
        $occurrences = [];

        foreach ([1, 5, 3, 2, 4] as $index => $occurrenceCount) {
            $id = $index + 1;
            $taxa[] = [$id, 'WEIGHT-' . $id, 'Weighted', null];

            for ($square = 0; $square < $id; $square++) {
                $occurrences[] = [$id, sprintf('SU%02dA', $square), 0, null];
            }

            // Unmapped occurrences vary the occurrence rank independently of square coverage.
            for ($extra = $id; $extra < $occurrenceCount + 5; $extra++) {
                $occurrences[] = [$id, null, 0, null];
            }
        }

        $this->seedTaxa($taxa);
        $this->seedOccurrences($occurrences);

        $this->assertSame('success', (new TaxonRarityService())->run()['status']);
        $this->assertSame([1, 5, 3, 2, 5], array_values($this->taxonCategoryMap()));
    }

    /**
     * Unrecorded peers cannot promote a below-median recorded species to common.
     */
    public function testRunDoesNotPromoteBelowMedianSpeciesToCommon(): void
    {
        $taxa = [];
        $occurrences = [];

        for ($id = 1; $id <= 1000; $id++) {
            $taxa[] = [$id, sprintf('TAXON-%04d', $id), 'Unassigned', null];

            if ($id <= 360) {
                [$squares, $records] = [1, 16];
            } elseif ($id <= 860) {
                [$squares, $records] = [3, 3 + ($id - 361) % 12];
            } elseif ($id <= 920) {
                [$squares, $records] = [2, 15];
            } else {
                [$squares, $records] = [4, 30];
            }

            for ($record = 0; $record < $records; $record++) {
                $occurrences[] = [$id, sprintf('SU%02dA', $record % $squares), 0, null];
            }
        }

        for ($id = 1001; $id <= 5000; $id++) {
            $taxa[] = [$id, sprintf('TAXON-%04d', $id), 'Unassigned', 5];
        }

        $this->seedTaxa($taxa);
        $this->seedOccurrences($occurrences);

        $this->assertSame('success', (new TaxonRarityService())->run()['status']);
        $categories = $this->taxonCategoryMap();
        $this->assertSame(3, $categories['TAXON-0920']);
        // These species have occupancy percentile 36.036 and occurrence percentile 50.0501.
        foreach (range(861, 920) as $id) {
            $this->assertSame(3, $categories[sprintf('TAXON-%04d', $id)]);
        }

        foreach (range(1001, 5000) as $id) {
            $this->assertNull($categories[sprintf('TAXON-%04d', $id)]);
        }
    }

    /**
     * Unrecorded species are cleared without affecting peers, including after records are blocked.
     */
    public function testUnrecordedSpeciesAreClearedAndDryRunsDoNotPersist(): void
    {
        $this->seedTaxa([
            [1, 'RARE', 'Recorded', null],
            [2, 'MIDDLE', 'Recorded', null],
            [3, 'COMMON', 'Recorded', null],
            [4, 'UNRECORDED', 'Recorded', 5],
            [5, 'BLOCKED-ONLY', 'Recorded', 4],
            [6, 'DELETED-ONLY', 'Recorded', 3],
            [7, 'ALREADY-NULL', 'Recorded', null],
        ]);
        $this->seedOccurrences([
            [1, 'SU00A', 0, null],
            [2, 'SU01A', 0, null],
            [2, 'SU01B', 0, null],
            [3, 'SU02A', 0, null],
            [3, 'SU02B', 0, null],
            [3, 'SU02C', 0, null],
            [5, 'SU03A', 1, null],
            [6, 'SU04A', 0, '2026-07-01 00:00:00'],
        ]);
        $service = new TaxonRarityService();
        $before = $this->taxonCategoryMap();
        $dryRun = $service->run(true);

        $this->assertSame('success', $dryRun['status']);
        $this->assertSame(7, $dryRun['fetched']);
        $this->assertSame(6, $dryRun['updated']);
        $this->assertSame(1, $dryRun['not changed']);
        $this->assertSame($before, $this->taxonCategoryMap());

        $result = $service->run();
        $this->assertSame('success', $result['status']);
        $this->assertSame(6, $result['updated']);
        $this->assertSame([
            'RARE' => 1,
            'MIDDLE' => 3,
            'COMMON' => 5,
            'UNRECORDED' => null,
            'BLOCKED-ONLY' => null,
            'DELETED-ONLY' => null,
            'ALREADY-NULL' => null,
        ], $this->taxonCategoryMap());
        $repeat = $service->run();
        $this->assertSame(0, $repeat['updated']);
        $this->assertSame(7, $repeat['not changed']);

        $this->db->table('occurrences')->where('species_id', 3)->update(['blocked' => 1]);
        $this->assertSame('success', $service->run()['status']);
        $categories = $this->taxonCategoryMap();
        $this->assertNull($categories['COMMON']);
        $this->assertSame(5, $categories['MIDDLE']);
    }

    /**
     * A group with no active occurrences has no calculated rarity categories.
     */
    public function testAllUnrecordedSpeciesHaveNullCategories(): void
    {
        $this->seedTaxa([
            [1, 'STALE', 'Unrecorded', 5],
            [2, 'UNSET', 'Unrecorded', null],
        ]);

        $result = (new TaxonRarityService())->run();

        $this->assertSame('success', $result['status']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, $result['not changed']);
        $this->assertSame(['STALE' => null, 'UNSET' => null], $this->taxonCategoryMap());
    }

    /**
     * Invalid weights fail explicitly without replacing stored categories.
     */
    public function testRunRejectsInvalidWeights(): void
    {
        $this->seedTaxa([[1, 'UNCHANGED', 'Invalid weights', 3]]);
        $this->seedOccurrences([[1, 'SU00A', 0, null]]);
        $config = config(Rarity::class);

        $invalidWeights = [
            [0.0, 0.0],
            [-1.0, 2.0],
            [1.0, -1.0],
            [INF, 1.0],
            [1.0, INF],
            [NAN, 1.0],
            [1.0, NAN],
            [PHP_FLOAT_MAX, PHP_FLOAT_MAX],
        ];

        foreach ($invalidWeights as [$squareWeight, $occurrenceWeight]) {
            $config->gridSquareWeight = $squareWeight;
            $config->occurrenceWeight = $occurrenceWeight;
            $result = (new TaxonRarityService())->run();

            $this->assertSame('failed', $result['status']);
            $this->assertSame(1, $result['errors']);
            $this->assertSame(['UNCHANGED' => 3], $this->taxonCategoryMap());
        }
    }

    /**
     * Exclusions clear categories and change peer rankings; clearing them restores eligibility.
     */
    public function testExclusionsRemoveSpeciesFromRankingsAndSupportReversal(): void
    {
        $this->seedTaxa([
            [1, 'RARE', 'Bees', null],
            [2, 'MIDDLE', 'Bees', null],
            [3, 'COMMON', 'Bees', null],
            [4, 'SINGLETON', 'Birds', null],
        ]);
        $this->seedOccurrences([
            [1, 'SU00A', 0, null],
            [2, 'SU01A', 0, null],
            [2, 'SU01B', 0, null],
            [3, 'SU02A', 0, null],
            [3, 'SU02B', 0, null],
            [3, 'SU02C', 0, null],
            [4, 'TV00A', 0, null],
        ]);
        $service = new TaxonRarityService();
        $this->assertSame('success', $service->run()['status']);
        $before = ['RARE' => 1, 'MIDDLE' => 3, 'COMMON' => 5, 'SINGLETON' => 1];
        $this->assertSame($before, $this->taxonCategoryMap());

        $this->db->table('taxa')->whereIn('id', [1, 4])
            ->update(['exclude_from_rarity_calculations' => 1]);
        $dryRun = $service->run(true);
        $this->assertSame('success', $dryRun['status']);
        $this->assertSame(3, $dryRun['updated']);
        $this->assertSame($before, $this->taxonCategoryMap());

        $result = $service->run();
        $this->assertSame('success', $result['status']);
        $this->assertSame(3, $result['updated']);
        $this->assertSame([
            'RARE' => null, 'MIDDLE' => 1, 'COMMON' => 5, 'SINGLETON' => null,
        ], $this->taxonCategoryMap());
        $this->assertSame(0, $service->run()['updated']);

        $this->db->table('taxa')->whereIn('id', [1, 4])
            ->update(['exclude_from_rarity_calculations' => 0]);
        $this->assertSame('success', $service->run()['status']);
        $this->assertSame($before, $this->taxonCategoryMap());
    }

    /**
     * Previously assigned categories are cleared even when no eligible species remain.
     */
    public function testAllExcludedSpeciesHaveNullCategoriesAfterRecalculation(): void
    {
        $this->seedTaxa([
            [1, 'EXCLUDED', 'Bees', 1],
            [2, 'UNGROUPED', null, 5],
        ]);
        $this->db->table('taxa')->update(['exclude_from_rarity_calculations' => 1]);
        $service = new TaxonRarityService();
        $this->assertSame(2, $service->run(true)['updated']);
        $this->assertSame(['EXCLUDED' => 1, 'UNGROUPED' => 5], $this->taxonCategoryMap());
        $result = $service->run();
        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['updated']);
        $this->assertSame(['EXCLUDED' => null, 'UNGROUPED' => null], $this->taxonCategoryMap());
    }

    /**
     * Seed species with initial categories and rarity groups.
     *
     * @param array<int, array{0:int, 1:string, 2:string|null, 3:int|null}> $rows
     */
    private function seedTaxa(array $rows): void
    {
        $payload = [];

        foreach ($rows as [$id, $identifier, $groupName, $category]) {
            $payload[] = [
                'id' => $id,
                'taxon_identifier' => $identifier,
                'species_id' => $id,
                'rarity_group_name' => $groupName,
                'rarity_category' => $category,
                'blocked' => 0,
                'deleted_at' => null,
            ];
        }

        $this->db->table('taxa')->insertBatch($payload);
    }

    /**
     * Seed species occurrences with the species projection used by rarity aggregation.
     *
     * @param array<int, array{0:int, 1:string|null, 2:int, 3:string|null}> $rows
     */
    private function seedOccurrences(array $rows): void
    {
        $payload = [];
        $occurrenceId = 1;

        foreach ($rows as [$taxonId, $gridRef2km, $blocked, $deletedAt]) {
            $payload[] = [
                'id' => $occurrenceId++,
                'taxon_id' => $taxonId,
                'species_id' => $taxonId,
                'grid_ref_2km' => $gridRef2km,
                'blocked' => $blocked,
                'deleted_at' => $deletedAt,
            ];
        }

        $this->db->table('occurrences')->insertBatch($payload);
    }

    /**
     * Read persisted categories without conflating an unset category with zero.
     *
     * @return array<string, int|null>
     */
    private function taxonCategoryMap(): array
    {
        $rows = $this->db->table('taxa')
            ->select('taxon_identifier, rarity_category')
            ->orderBy('id', 'asc')
            ->get()
            ->getResultArray();

        $categories = [];

        foreach ($rows as $row) {
            $categories[(string) $row['taxon_identifier']] = $row['rarity_category'] === null
                ? null : (int) $row['rarity_category'];
        }

        return $categories;
    }
}