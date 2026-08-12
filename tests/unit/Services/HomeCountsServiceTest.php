<?php

namespace Tests;

use App\Services\HomeCountsService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class HomeCountsServiceTest extends CIUnitTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = db_connect();
        $prefix = $this->db->getPrefix();

        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'occurrences');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxa');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'taxon_ranks');
        $this->db->query('DROP TABLE IF EXISTS ' . $prefix . 'geographic_regions');

        $this->db->query('CREATE TABLE ' . $prefix . 'occurrences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            blocked INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            taxon_rank_id INTEGER NULL,
            blocked INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'taxon_ranks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            is_reporting INTEGER NOT NULL DEFAULT 0
        )');

        $this->db->query('CREATE TABLE ' . $prefix . 'geographic_regions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            deleted_at DATETIME NULL
        )');

        cache()->delete('home_panel_counts_v1');
    }

    protected function tearDown(): void
    {
        cache()->delete('home_panel_counts_v1');

        parent::tearDown();
    }

    public function testGetCountsReturnsActiveOnlyRowsOnCacheMiss(): void
    {
        $this->db->table('occurrences')->insertBatch([
            ['id' => 1, 'blocked' => 0, 'deleted_at' => null],
            ['id' => 2, 'blocked' => 1, 'deleted_at' => null],
            ['id' => 3, 'blocked' => 0, 'deleted_at' => '2026-07-01 00:00:00'],
            ['id' => 4, 'blocked' => 0, 'deleted_at' => null],
        ]);

        $this->db->table('taxa')->insertBatch([
            ['id' => 1, 'taxon_rank_id' => 1, 'blocked' => 0, 'deleted_at' => null],
            ['id' => 2, 'taxon_rank_id' => 1, 'blocked' => 1, 'deleted_at' => null],
            ['id' => 3, 'taxon_rank_id' => 1, 'blocked' => 0, 'deleted_at' => '2026-07-01 00:00:00'],
        ]);
        $this->db->table('taxon_ranks')->insert(['id' => 1, 'is_reporting' => 1]);

        $this->db->table('geographic_regions')->insertBatch([
            ['id' => 1, 'deleted_at' => null],
            ['id' => 2, 'deleted_at' => '2026-07-01 00:00:00'],
            ['id' => 3, 'deleted_at' => null],
        ]);

        $service = new HomeCountsService();
        $counts = $service->getCounts();

        $this->assertSame(2, $counts['occurrences']);
        $this->assertSame(1, $counts['taxa']);
        $this->assertSame(2, $counts['geographic_regions']);
    }

    public function testGetCountsUsesCachedPayloadWhenAvailable(): void
    {
        cache()->save('home_panel_counts_v1', [
            'occurrences' => 99,
            'taxa' => 88,
            'geographic_regions' => 77,
        ], 300);

        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->getPrefix() . 'occurrences');
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->getPrefix() . 'taxa');
        $this->db->query('DROP TABLE IF EXISTS ' . $this->db->getPrefix() . 'geographic_regions');

        $service = new HomeCountsService();
        $counts = $service->getCounts();

        $this->assertSame(99, $counts['occurrences']);
        $this->assertSame(88, $counts['taxa']);
        $this->assertSame(77, $counts['geographic_regions']);
    }
}
