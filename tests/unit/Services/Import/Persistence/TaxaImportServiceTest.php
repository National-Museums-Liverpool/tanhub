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
            taxon_group_id INTEGER NOT NULL,
            recording_scheme_id INTEGER NULL,
            conservation_status VARCHAR(10) NULL,
            rarity_group_name VARCHAR(100) NOT NULL,
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

        $this->assertSame(1, $counts['inserted']);
        $this->assertSame(0, $counts['updated']);
        $row = $this->db->table('taxa')->where('taxon_identifier', 'TX-NEW-1')->get()->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('Scheme A Title', (string) $row['rarity_group_name']);
    }

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
            'conservation_status' => null,
            'rarity_group_name' => 'Manual rarity',
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
