<?php

namespace Tests;

use App\Services\Import\Persistence\TaxaImportService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class TaxaImportServiceTest extends CIUnitTestCase
{
    protected $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = db_connect();
        $prefix = $this->db->getPrefix();
        $ranks = config('Import')->taxonRanks;

        foreach (['taxa', 'taxon_ranks', 'recording_schemes', 'taxon_groups'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS ' . $prefix . $table);
        }

        $rankColumnsSql = '';

        foreach ($ranks as $rank) {
            $rankColumnsSql .= ', ' . strtolower((string) $rank) . '_id INTEGER NULL';
        }

        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'taxon_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            external_key VARCHAR(100) NULL,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'recording_schemes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            external_key VARCHAR(100) NULL,
            title VARCHAR(200) NOT NULL,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'taxon_ranks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            rank VARCHAR(100) NULL,
            sort_order INTEGER NOT NULL DEFAULT 0,
            is_reporting INTEGER NOT NULL DEFAULT 0,
            deleted_at DATETIME NULL
        )');

        $this->db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'taxa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            taxon_identifier VARCHAR(100) NOT NULL,
            scientific_name_identifier VARCHAR(100) NOT NULL,
            scientific_name VARCHAR(200) NOT NULL,
            scientific_name_authorship VARCHAR(100) NULL,
            vernacular_name VARCHAR(200) NOT NULL,
            taxon_rank_id INTEGER NOT NULL,
            parent_taxon_id INTEGER NULL,
            taxon_group_id INTEGER NOT NULL,
            id_difficulty INTEGER NULL,
            recording_scheme_id INTEGER NULL,
            gb_red_list TEXT NULL,
            gb_status TEXT NULL,
            rarity_group_name VARCHAR(100) NOT NULL,
            rarity_category INTEGER NULL,
            exclude_from_rarity_calculations INTEGER NOT NULL DEFAULT 0,
            exclude_from_rarity_calculations_comment TEXT NULL,
            blocked INTEGER NOT NULL DEFAULT 0,
            blocked_reason TEXT NULL,
            deleted_at DATETIME NULL' . $rankColumnsSql . '
        )');
            $this->db->table('taxa')->emptyTable();
            $this->db->table('taxon_ranks')->emptyTable();
            $this->db->table('recording_schemes')->emptyTable();
            $this->db->table('taxon_groups')->emptyTable();
    }

    public function testInsertDefaultsRarityGroupToRecordingSchemeTitle(): void
    {
        $this->seedSharedLookupRows();

        $service = new TaxaImportService();
        $counts = $service->import([
            [
                'taxon_identifier' => 'TX-NEW-1',
                'scientific_name_identifier' => 'SCI-NEW-1',
                'scientific_name' => 'Taxon new one',
                'vernacular_name' => 'New taxon',
                'taxon_group_external_key' => 'group-a',
                'recording_scheme_external_key' => 'scheme-a',
                'taxon_rank' => 'Family',
                'higher_taxa' => [],
            ],
        ]);

        $this->assertSame(1, $counts['fetched']);
        $this->assertSame(1, $counts['processed']);
        $this->assertSame(0, $counts['updated']);
        $row = $this->db->table('taxa')->where('taxon_identifier', 'TX-NEW-1')->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('Scheme A Title', (string) $row['rarity_group_name']);
    }

    /**
     * Taxonomy refreshes preserve locally curated rarity groups and species exclusions.
     */
    public function testUpdateDoesNotOverwriteExistingRarityGroupName(): void
    {
        $this->seedSharedLookupRows();

        $this->db->table('taxa')->insert([
            'taxon_identifier' => 'TX-UPD-1',
            'scientific_name_identifier' => 'SCI-UPD-1',
            'scientific_name' => 'Existing taxon',
            'scientific_name_authorship' => null,
            'vernacular_name' => 'Existing name',
            'taxon_group_id' => 1,
            'recording_scheme_id' => 1,
            'taxon_rank_id' => 1,
            'gb_red_list' => null,
            'rarity_group_name' => 'Manual rarity',
            'exclude_from_rarity_calculations' => 1,
            'exclude_from_rarity_calculations_comment' => 'Curated exclusion',
            'blocked' => 0,
            'blocked_reason' => null,
            'deleted_at' => null,
            'family_id' => null,
        ]);

        $service = new TaxaImportService();
        $counts = $service->import([
            [
                'taxon_identifier' => 'TX-UPD-1',
                'scientific_name_identifier' => 'SCI-UPD-1',
                'scientific_name' => 'Existing taxon updated',
                'vernacular_name' => 'Updated vernacular',
                'taxon_group_external_key' => 'group-a',
                'recording_scheme_external_key' => 'scheme-a',
                'taxon_rank' => 'Family',
                'higher_taxa' => [],
            ],
        ]);

        $this->assertSame(0, $counts['inserted']);
        $this->assertSame(1, $counts['updated']);

        $row = $this->db->table('taxa')->where('taxon_identifier', 'TX-UPD-1')->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('Manual rarity', (string) $row['rarity_group_name']);
        $this->assertSame('Existing taxon updated', (string) $row['scientific_name']);
        $this->assertSame(1, (int) $row['exclude_from_rarity_calculations']);
        $this->assertSame('Curated exclusion', $row['exclude_from_rarity_calculations_comment']);
    }

    public function testPersistsImmediateParentIdentifier(): void
    {
        $this->seedSharedLookupRows();
        $this->db->table('taxa')->insert([
            'taxon_identifier' => 'TX-PARENT-1',
            'scientific_name_identifier' => 'SCI-PARENT-1',
            'scientific_name' => 'Parent taxon',
            'vernacular_name' => 'Parent',
            'taxon_group_id' => 1,
            'recording_scheme_id' => 1,
            'taxon_rank_id' => 1,
            'rarity_group_name' => 'Scheme A Title',
            'blocked' => 0,
            'deleted_at' => null,
        ]);

        $service = new TaxaImportService();
        $counts = $service->import([
            [
                'taxon_identifier' => 'TX-CHILD-1',
                'scientific_name_identifier' => 'SCI-CHILD-1',
                'scientific_name' => 'Child taxon',
                'vernacular_name' => 'Child',
                'taxon_group_external_key' => 'group-a',
                'recording_scheme_external_key' => 'scheme-a',
                'taxon_rank' => 'Family',
                'parent_taxon_identifier' => 'TX-PARENT-1',
                'higher_taxa' => [],
            ],
        ]);

        $this->assertSame(1, $counts['inserted']);
        $row = $this->db->table('taxa')->where('taxon_identifier', 'TX-CHILD-1')->get()->getRowArray();
        $parent = $this->db->table('taxa')->where('taxon_identifier', 'TX-PARENT-1')->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertNotNull($parent);
        $this->assertSame((int) $parent['id'], (int) $row['parent_taxon_id']);
    }

    /**
     * Lists are stable, lossless beyond the old field limit, and cleared on refresh.
     */
    public function testDesignationListsAreNormalizedAndClearedOnReimport(): void
    {
        $this->seedSharedLookupRows();
        $row = [
            'taxon_identifier' => 'TX-LISTS',
            'scientific_name_identifier' => 'SCI-LISTS',
            'scientific_name' => 'Designation test',
            'vernacular_name' => '',
            'taxon_group_external_key' => 'group-a',
            'recording_scheme_external_key' => 'scheme-a',
            'taxon_rank' => 'Family',
            'gb_red_list' => ' VU;LC;VU; ;EN ',
            'gb_status' => 'Nb;Nationally Rare;Na;Nb',
        ];
        $service = new TaxaImportService();
        $this->assertSame(1, $service->import([$row])['inserted']);
        $stored = $this->db->table('taxa')->where('taxon_identifier', 'TX-LISTS')->get()->getRowArray();
        $this->assertSame('EN;LC;VU', $stored['gb_red_list']);
        $this->assertSame('Na;Nationally Rare;Nb', $stored['gb_status']);
        $this->assertSame(1, $service->import([$row])['updated']);
        $this->assertSame(1, $this->db->table('taxa')->countAllResults());
        unset($row['gb_red_list']);
        $row['gb_status'] = ' ; ';
        $this->assertSame(1, $service->import([$row])['updated']);
        $stored = $this->db->table('taxa')->where('taxon_identifier', 'TX-LISTS')->get()->getRowArray();
        $this->assertNull($stored['gb_red_list']);
        $this->assertNull($stored['gb_status']);
    }

    /**
     * Invalid or oversized values stop the import without a truncated successful insert.
     */
    public function testUnrepresentableDesignationListsFailBeforeWriting(): void
    {
        $this->seedSharedLookupRows();
        $service = new TaxaImportService();
        foreach ([str_repeat('a', 65536), "\xff", "LC\0VU", ['LC']] as $value) {
            $counts = $service->import([[
                'taxon_identifier' => 'TX-INVALID',
                'scientific_name_identifier' => 'SCI-INVALID',
                'scientific_name' => 'Invalid designation',
                'taxon_group_external_key' => 'group-a',
                'taxon_rank' => 'Family',
                'gb_status' => $value,
            ]]);
            $this->assertSame(1, $counts['errors']);
            $this->assertSame(0, $this->db->table('taxa')->countAllResults());
        }
    }

    private function seedSharedLookupRows(): void
    {
        $this->db->table('taxon_groups')->insert([
            'id' => 1,
            'external_key' => 'group-a',
            'deleted_at' => null,
        ]);

        $this->db->table('recording_schemes')->insert([
            'id' => 1,
            'external_key' => 'scheme-a',
            'title' => 'Scheme A Title',
            'deleted_at' => null,
        ]);

        $this->db->table('taxon_ranks')->insert([
            'id' => 1,
            'rank' => 'Family',
            'deleted_at' => null,
        ]);
    }
}
