<?php

namespace App\Database\Migrations;

use App\Services\TaxonDesignationService;
use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Add locally curated GB designations while preserving imported values and taxonomy keys.
 */
class AddGbDesignationOverrides extends Migration
{
    /**
     * Add nullable overrides, matching each source column's MySQL charset and collation.
     *
     * @return void
     * @throws RuntimeException If source metadata is missing or a column cannot be added.
     */
    public function up(): void
    {
        foreach (TaxonDesignationService::FIELDS as $field) {
            $column = $field . '_override';
            $definition = [$column => ['type' => 'TEXT', 'null' => true, 'default' => null]];
            if ($this->db->DBDriver === 'MySQLi') {
                $source = $this->db->query('SELECT CHARACTER_SET_NAME, COLLATION_NAME
                    FROM information_schema.COLUMNS
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                    [$this->db->prefixTable('taxa'), $field])->getRowArray();
                if ($source === null || $source['CHARACTER_SET_NAME'] === null || $source['COLLATION_NAME'] === null) {
                    throw new RuntimeException('Imported GB designation charset and collation could not be read.');
                }
                // Forge has no portable per-column collation attribute; quote trusted server metadata.
                $definition = $this->db->escapeIdentifiers($column) . ' TEXT CHARACTER SET '
                    . $this->db->escapeIdentifiers($source['CHARACTER_SET_NAME']) . ' COLLATE '
                    . $this->db->escapeIdentifiers($source['COLLATION_NAME']) . ' NULL DEFAULT NULL';
            }
            if (! $this->forge->addColumn('taxa', $definition)) {
                throw new RuntimeException('GB designation override storage could not be added.');
            }
        }
    }

    /**
     * Discard only local overrides without rebuilding SQLite taxonomy relationships.
     *
     * @return void
     * @throws RuntimeException If an override column cannot be removed.
     */
    public function down(): void
    {
        foreach (TaxonDesignationService::FIELDS as $field) {
            $column = $field . '_override';
            if ($this->db->DBDriver === 'SQLite3') {
                // Native DROP retains inbound/self-referencing keys, indexes and unrelated triggers.
                $table = $this->db->protectIdentifiers($this->db->prefixTable('taxa'));
                $result = $this->db->query('ALTER TABLE ' . $table . ' DROP COLUMN '
                    . $this->db->protectIdentifiers($column));
            } else {
                $result = $this->forge->dropColumn('taxa', $column);
            }
            if ($result === false) {
                throw new RuntimeException('GB designation override storage could not be removed.');
            }
        }
    }
}
