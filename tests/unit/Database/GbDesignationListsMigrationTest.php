<?php

namespace Tests;

use App\Database\Migrations\AddGbDesignationLists;
use App\Database\Migrations\AddGbDesignationOverrides;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use RuntimeException;

/**
 * Verify designation migration data preservation and SQLite foreign-key stability.
 *
 * @internal
 */
final class GbDesignationListsMigrationTest extends CIUnitTestCase
{
    /** @var BaseConnection Isolated database, never the application's configured database. */
    private BaseConnection $isolatedDb;

    /** @var AddGbDesignationLists Migration bound to the isolated database. */
    private AddGbDesignationLists $migration;

    /**
     * Build legacy taxa with self-referencing and inbound keys and existing status data.
     */
    protected function setUp(): void
    {
        parent::setUp();
        require_once APPPATH . 'Database/Migrations/2026-11-06-160000_AddGbDesignationLists.php';
        $this->isolatedDb = Database::connect([
            'DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => 'gd_', 'DBDebug' => true,
        ], false);
        $this->isolatedDb->query('PRAGMA foreign_keys = ON');
        $this->isolatedDb->query('CREATE TABLE gd_taxa (
            id INTEGER PRIMARY KEY,
            parent_taxon_id INTEGER NULL REFERENCES gd_taxa(id) ON UPDATE CASCADE ON DELETE SET NULL,
            conservation_status VARCHAR(10) NULL
        )');
        $this->isolatedDb->query('CREATE INDEX gd_taxa_parent ON gd_taxa(parent_taxon_id)');
        $this->isolatedDb->query('CREATE TABLE gd_occurrences (
            id INTEGER PRIMARY KEY,
            taxon_id INTEGER NOT NULL REFERENCES gd_taxa(id) ON UPDATE CASCADE ON DELETE RESTRICT
        )');
        $this->isolatedDb->table('taxa')->insert(['id' => 1, 'conservation_status' => 'LC']);
        $this->isolatedDb->table('taxa')->insert(['id' => 2, 'parent_taxon_id' => 1, 'conservation_status' => null]);
        $this->isolatedDb->table('occurrences')->insert(['id' => 1, 'taxon_id' => 2]);
        $this->migration = new AddGbDesignationLists(Database::forge($this->isolatedDb));
    }

    /**
     * Release the isolated in-memory connection.
     */
    protected function tearDown(): void
    {
        $this->isolatedDb->close();
        parent::tearDown();
    }

    /**
     * Rename/widen and rollback preserve values, indexes and all foreign-key targets.
     */
    public function testUpgradeAndRollbackPreserveDataAndReferences(): void
    {
        $this->migration->up();
        $rows = $this->isolatedDb->table('taxa')->orderBy('id')->get()->getResultArray();
        $this->assertSame('LC', $rows[0]['gb_red_list']);
        $this->assertNull($rows[0]['gb_status']);
        $this->assertNull($rows[1]['gb_red_list']);
        $this->assertArrayNotHasKey('conservation_status', $rows[0]);
        $fields = array_column($this->isolatedDb->getFieldData('taxa'), 'type', 'name');
        $this->assertSame('TEXT', $fields['gb_red_list']);
        $this->assertReferences();
        $this->migration->down();
        $rows = $this->isolatedDb->table('taxa')->orderBy('id')->get()->getResultArray();
        $this->assertSame('LC', $rows[0]['conservation_status']);
        $this->assertNull($rows[1]['conservation_status']);
        $this->assertArrayNotHasKey('gb_status', $rows[0]);
        $this->assertReferences();
    }

    /**
     * Rollback refuses oversized red-list values before changing either column.
     */
    public function testRollbackRefusesTruncation(): void
    {
        $this->migration->up();
        $this->isolatedDb->table('taxa')->where('id', 1)->update([
            'gb_red_list' => 'EN;LC;NT;VU', 'gb_status' => 'Na;Nb',
        ]);
        try {
            $this->migration->down();
            $this->fail('Rollback must refuse red-list truncation.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('exceed 10 characters', $exception->getMessage());
        }
        $row = $this->isolatedDb->table('taxa')->where('id', 1)->get()->getRowArray();
        $this->assertSame('EN;LC;NT;VU', $row['gb_red_list']);
        $this->assertSame('Na;Nb', $row['gb_status']);
        $this->assertReferences();
    }

    /**
     * Override addition and rollback retain source data, relationships and unrelated triggers.
     */
    public function testOverrideMigrationPreservesImportedDataAndTaxonomy(): void
    {
        $this->migration->up();
        require_once APPPATH . 'Database/Migrations/2026-11-11-120000_AddGbDesignationOverrides.php';
        $migration = new AddGbDesignationOverrides(Database::forge($this->isolatedDb));
        $this->isolatedDb->query('CREATE TABLE gd_audit (taxon_id INTEGER)');
        $this->isolatedDb->query('CREATE TRIGGER gd_taxa_audit AFTER UPDATE OF gb_red_list ON gd_taxa
            BEGIN INSERT INTO gd_audit (taxon_id) VALUES (NEW.id); END');
        $migration->up();
        $row = $this->isolatedDb->table('taxa')->where('id', 1)->get()->getRowArray();
        $this->assertSame('LC', $row['gb_red_list']);
        $this->assertNull($row['gb_red_list_override']);
        $this->assertNull($row['gb_status_override']);
        $this->isolatedDb->table('taxa')->where('id', 1)->update([
            'gb_red_list_override' => 'VU', 'gb_status_override' => 'Na',
        ]);
        $this->assertReferences();
        $migration->down();
        $row = $this->isolatedDb->table('taxa')->where('id', 1)->get()->getRowArray();
        $this->assertSame('LC', $row['gb_red_list']);
        $this->assertArrayNotHasKey('gb_red_list_override', $row);
        $this->assertArrayNotHasKey('gb_status_override', $row);
        $this->assertReferences();
        $this->isolatedDb->table('taxa')->where('id', 1)->update(['gb_red_list' => 'EN']);
        $this->assertSame(1, $this->isolatedDb->table('audit')->countAllResults());
    }

    /**
     * Assert the migration restores SQLite settings, keys and the existing parent index.
     */
    private function assertReferences(): void
    {
        foreach (['gd_taxa', 'gd_occurrences'] as $table) {
            $keys = $this->isolatedDb->query('PRAGMA foreign_key_list(' . $table . ')')->getResultArray();
            $this->assertSame('gd_taxa', $keys[0]['table']);
            $this->assertSame('CASCADE', $keys[0]['on_update']);
            $this->assertSame($table === 'gd_taxa' ? 'SET NULL' : 'RESTRICT', $keys[0]['on_delete']);
        }
        $this->assertSame([], $this->isolatedDb->query('PRAGMA foreign_key_check')->getResultArray());
        $this->assertSame(1, (int) $this->isolatedDb->query('PRAGMA foreign_keys')->getRowArray()['foreign_keys']);
        $this->assertSame(0, (int) $this->isolatedDb->query('PRAGMA legacy_alter_table')->getRowArray()['legacy_alter_table']);
        $this->assertContains('gd_taxa_parent',
            array_column($this->isolatedDb->query('PRAGMA index_list(gd_taxa)')->getResultArray(), 'name'));
    }
}
