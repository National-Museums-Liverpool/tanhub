<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Ensures a taxon has at most one published primary media item.
 */
class EnforceSinglePrimaryTaxonMedia extends Migration
{
    /**
     * Normalize existing data and add the single-primary constraint.
     *
     * @return void
     */
    public function up(): void
    {
        $table = $this->db->prefixTable('taxon_media');
        $this->normalizeDuplicatePrimaries($table);

        if ($this->db->DBDriver === 'MySQLi') {
            $this->createMySqlTriggers($table);

            return;
        }

        $this->db->query(
            'CREATE UNIQUE INDEX uq_taxon_media_primary_taxon ON ' . $table
            . ' (taxon_id) WHERE is_primary = 1 AND deleted_at IS NULL AND bulk_import_id IS NULL'
        );
    }

    /**
     * Add MySQL triggers that reject a second published primary media item.
     *
     * @param string $table Fully qualified media table name.
     * @return void
     */
    private function createMySqlTriggers(string $table): void
    {
        $insertTrigger = $table . '_single_primary_insert';
        $updateTrigger = $table . '_single_primary_update';
        $condition = 'is_primary = 1 AND deleted_at IS NULL AND bulk_import_id IS NULL';
        $message = 'A taxon can have only one primary media item.';

        $this->db->query(
            'CREATE TRIGGER ' . $insertTrigger . ' BEFORE INSERT ON ' . $table . ' FOR EACH ROW '
            . 'BEGIN IF NEW.is_primary = 1 AND NEW.deleted_at IS NULL AND NEW.bulk_import_id IS NULL '
            . 'AND EXISTS (SELECT 1 FROM ' . $table . ' WHERE taxon_id = NEW.taxon_id AND ' . $condition . ') '
            . "THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '" . $message . "'; END IF; END"
        );
        $this->db->query(
            'CREATE TRIGGER ' . $updateTrigger . ' BEFORE UPDATE ON ' . $table . ' FOR EACH ROW '
            . 'BEGIN IF NEW.is_primary = 1 AND NEW.deleted_at IS NULL AND NEW.bulk_import_id IS NULL '
            . 'AND EXISTS (SELECT 1 FROM ' . $table . ' WHERE taxon_id = NEW.taxon_id '
            . 'AND id != OLD.id AND ' . $condition . ') '
            . "THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '" . $message . "'; END IF; END"
        );
    }

    /**
     * Remove duplicate primary flags while retaining the lowest media ID.
     *
     * @param string $table Fully qualified media table name.
     * @return void
     */
    private function normalizeDuplicatePrimaries(string $table): void
    {
        $groups = $this->db->query(
            'SELECT taxon_id FROM ' . $table
            . ' WHERE is_primary = 1 AND deleted_at IS NULL AND bulk_import_id IS NULL'
            . ' GROUP BY taxon_id HAVING COUNT(*) > 1'
        )->getResultArray();

        foreach ($groups as $group) {
            $rows = $this->db->query(
                'SELECT id FROM ' . $table
                . ' WHERE taxon_id = ? AND is_primary = 1 AND deleted_at IS NULL AND bulk_import_id IS NULL'
                . ' ORDER BY id ASC',
                [(int) $group['taxon_id']]
            )->getResultArray();

            foreach (array_slice($rows, 1) as $row) {
                $this->db->table('taxon_media')
                    ->where('id', (int) $row['id'])
                    ->update(['is_primary' => 0]);
            }
        }
    }

    /**
     * Remove the single-primary constraint.
     *
     * @return void
     */
    public function down(): void
    {
        $table = $this->db->prefixTable('taxon_media');

        if ($this->db->DBDriver === 'MySQLi') {
            $this->db->query('DROP TRIGGER IF EXISTS ' . $table . '_single_primary_insert');
            $this->db->query('DROP TRIGGER IF EXISTS ' . $table . '_single_primary_update');

            return;
        }

        $this->db->query('DROP INDEX uq_taxon_media_primary_taxon');
    }
}
