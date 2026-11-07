<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Preserves GB Red List data and adds independent multi-value GB Status storage.
 */
class AddGbDesignationLists extends Migration
{
    /**
     * Rename and widen the imported red-list field, then add nullable GB Status.
     *
     * @return void
     */
    public function up(): void
    {
        $this->changeRedListColumn('conservation_status', [
            'name' => 'gb_red_list',
            'type' => 'TEXT',
            'null' => true,
        ]);
        if (! $this->forge->addColumn('taxa', ['gb_status' => ['type' => 'TEXT', 'null' => true]])) {
            throw new RuntimeException('GB Status storage could not be added.');
        }
    }

    /**
     * Restore the old field only when its values fit; discard imported GB Status.
     *
     * @return void
     * @throws RuntimeException If restoring VARCHAR(10) would truncate red-list data.
     */
    public function down(): void
    {
        $lengthFunction = $this->db->DBDriver === 'SQLite3' ? 'LENGTH' : 'CHAR_LENGTH';
        if ($this->db->table('taxa')->where($lengthFunction . '(gb_red_list) >', 10)->countAllResults() > 0) {
            throw new RuntimeException('Cannot restore conservation_status: GB Red List values exceed 10 characters.');
        }
        $this->changeRedListColumn('gb_red_list', [
            'name' => 'conservation_status',
            'type' => 'VARCHAR',
            'constraint' => 10,
            'null' => true,
        ]);
        $this->dropDesignationColumn('gb_status');
    }

    /**
     * Change the red-list column while preserving inbound and self-referencing keys.
     *
     * @param string $column Current column name.
     * @param array<string, mixed> $definition Target Forge definition.
     * @return void
     */
    private function changeRedListColumn(string $column, array $definition): void
    {
        if ($this->db->DBDriver === 'SQLite3') {
            $version = $this->db->query('SELECT sqlite_version() AS version')->getRowArray()['version'];
            if (version_compare($version, '3.35.0', '<')) {
                throw new RuntimeException('GB designation migration requires SQLite 3.35 or later.');
            }
            // Forge rebuilds lose FK actions and triggers; native column operations retain them.
            $name = $definition['name'];
            unset($definition['name']);
            if (! $this->forge->addColumn('taxa', [$name => $definition])) {
                throw new RuntimeException('GB Red List storage could not be added.');
            }
            $table = $this->db->protectIdentifiers($this->db->prefixTable('taxa'));
            $target = $this->db->protectIdentifiers($name);
            $source = $this->db->protectIdentifiers($column);
            if (! $this->db->query("UPDATE {$table} SET {$target} = {$source}")) {
                throw new RuntimeException('GB Red List values could not be copied.');
            }
            $this->dropDesignationColumn($column);
        } elseif (! $this->forge->modifyColumn('taxa', [$column => $definition])) {
            throw new RuntimeException('GB Red List storage could not be changed.');
        }
    }

    /**
     * Remove an unindexed designation column without rebuilding SQLite foreign keys.
     *
     * @param string $column Designation column to remove.
     * @return void
     */
    private function dropDesignationColumn(string $column): void
    {
        if ($this->db->DBDriver === 'SQLite3') {
            $table = $this->db->protectIdentifiers($this->db->prefixTable('taxa'));
            $field = $this->db->protectIdentifiers($column);
            if (! $this->db->query("ALTER TABLE {$table} DROP COLUMN {$field}")) {
                throw new RuntimeException('GB designation storage could not be removed.');
            }
        } elseif (! $this->forge->dropColumn('taxa', $column)) {
            throw new RuntimeException('GB designation storage could not be removed.');
        }
    }
}
