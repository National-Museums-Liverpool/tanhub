<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class TaxonMediaFilesDeliveryTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $migrate = service('migrations');
        $migrate->setNamespace(null);
        $migrate->latest();

        $this->seedDeliveryFixture();
    }

    public function testShowReturnsOriginalMediaAsset(): void
    {
        $result = $this->get('taxon-media/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

        $result->assertStatus(200);
        $result->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('original-bytes', (string) $result->response()->getBody());
    }

    public function testVariantReturnsVariantMediaAsset(): void
    {
        $result = $this->get('taxon-media/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/thumbnail');

        $result->assertStatus(200);
        $result->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame('thumbnail-bytes', (string) $result->response()->getBody());
    }

    public function testUnknownUuidReturnsNotFound(): void
    {
        $result = $this->get('taxon-media/ffffffff-ffff-4fff-8fff-ffffffffffff');

        $result->assertStatus(404);
    }

    public function testUnknownVariantReturnsNotFound(): void
    {
        $result = $this->get('taxon-media/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/large');

        $result->assertStatus(404);
    }

    private function seedDeliveryFixture(): void
    {
        $db = db_connect();
        $now = date('Y-m-d H:i:s');
        $uuid = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

        $db->query('PRAGMA foreign_keys = OFF');

        $db->table('taxon_media_variants')->emptyTable();
        $db->table('taxon_media')->emptyTable();
        $db->table('geographic_regions_occurrences')->emptyTable();
        $db->table('taxon_names')->emptyTable();
        $db->table('taxon_stats')->emptyTable();
        $db->table('taxon_year_stats')->emptyTable();
        $db->table('occurrences')->emptyTable();
        $db->table('taxa')->emptyTable();
        $db->table('taxon_groups')->emptyTable();
        $db->table('taxon_ranks')->emptyTable();
        $db->table('recording_schemes')->emptyTable();

        $db->query('PRAGMA foreign_keys = ON');

        $db->table('taxon_groups')->insert([
            'id' => 1,
            'title' => 'Bees',
            'friendly' => 'Bee species',
            'external_key' => 'bees',
            'indicia_taxon_group_id' => 10,
            'implied' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $db->table('taxon_ranks')->insert([
            'id' => 1,
            'rank' => 'Species',
            'abbr' => 'sp',
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $db->table('recording_schemes')->insert([
            'id' => 1,
            'external_key' => 'SCHEME-0001',
            'title' => 'Alpha scheme',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $db->table('taxa')->insert([
            'id' => 1,
            'taxon_identifier' => 'NHMSYS0021054498',
            'scientific_name_identifier' => 'TVK-001',
            'scientific_name' => 'Bombus terrestris',
            'scientific_name_authorship' => 'Linnaeus, 1758',
            'vernacular_name' => 'Buff-tailed Bumblebee',
            'taxon_rank_id' => 1,
            'order_id' => null,
            'superfamily_id' => null,
            'family_id' => null,
            'genus_id' => null,
            'species_id' => null,
            'taxon_group_id' => 1,
            'id_difficulty' => 2,
            'recording_scheme_id' => 1,
            'conservation_status' => 'LC',
            'taxon_remarks' => null,
            'rarity_group_name' => 'common',
            'blocked' => 0,
            'blocked_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $baseDir = rtrim((string) WRITEPATH, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'taxon-media'
            . DIRECTORY_SEPARATOR . '1'
            . DIRECTORY_SEPARATOR . $uuid;

        if (! is_dir($baseDir)) {
            mkdir($baseDir, 0775, true);
        }

        file_put_contents($baseDir . DIRECTORY_SEPARATOR . 'original.jpg', 'original-bytes');
        file_put_contents($baseDir . DIRECTORY_SEPARATOR . 'thumbnail.jpg', 'thumbnail-bytes');

        $db->table('taxon_media')->insert([
            'id' => 1,
            'uuid' => $uuid,
            'taxon_id' => 1,
            'original_filename' => 'original.jpg',
            'storage_path' => '1/' . $uuid . '/original.jpg',
            'mime_type' => 'image/jpeg',
            'bytes' => 14,
            'width' => 100,
            'height' => 100,
            'alt_text' => null,
            'caption' => null,
            'attribution' => null,
            'license' => null,
            'sort_order' => 0,
            'is_primary' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $db->table('taxon_media_variants')->insert([
            'taxon_media_id' => 1,
            'variant_key' => 'thumbnail',
            'storage_path' => '1/' . $uuid . '/thumbnail.jpg',
            'mime_type' => 'image/jpeg',
            'bytes' => 15,
            'width' => 50,
            'height' => 50,
            'created_at' => $now,
        ]);
    }
}
