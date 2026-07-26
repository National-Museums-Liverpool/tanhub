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
            rarity_group_name VARCHAR(100) NULL,
            rarity_category INTEGER NULL,
            blocked INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'occurrences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            taxon_id INTEGER NOT NULL,
            grid_ref_2km VARCHAR(5) NULL,
            blocked INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $rarityConfig = config(Rarity::class);
        $rarityConfig->squareWeight = 1.0;
        $rarityConfig->occurrenceWeight = 1.0;
    }

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
            [2, 'SU01A', 0, null],
            [3, 'SU02A', 0, null],
            [3, 'SU02B', 0, null],
            [4, 'SU03A', 0, null],
            [4, 'SU03B', 0, null],
            [4, 'SU03C', 0, null],
            [5, 'SU04A', 0, null],
            [5, 'SU04B', 0, null],
            [5, 'SU04C', 0, null],
            [5, 'SU04D', 0, null],
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
        $rarityConfig->squareWeight = 0.0;
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
     * @param array<int, array{0:int, 1:string, 2:string|null, 3:int|null}> $rows
     */
    private function seedTaxa(array $rows): void
    {
        $payload = [];

        foreach ($rows as [$id, $identifier, $groupName, $category]) {
            $payload[] = [
                'id' => $id,
                'taxon_identifier' => $identifier,
                'rarity_group_name' => $groupName,
                'rarity_category' => $category,
                'blocked' => 0,
                'deleted_at' => null,
            ];
        }

        $this->db->table('taxa')->insertBatch($payload);
    }

    /**
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
                'grid_ref_2km' => $gridRef2km,
                'blocked' => $blocked,
                'deleted_at' => $deletedAt,
            ];
        }

        $this->db->table('occurrences')->insertBatch($payload);
    }

    /**
     * @return array<string, int>
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
            $categories[(string) $row['taxon_identifier']] = (int) $row['rarity_category'];
        }

        return $categories;
    }
}