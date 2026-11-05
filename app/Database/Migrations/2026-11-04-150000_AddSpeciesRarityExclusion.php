<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds persistent species exclusions independent of measured and expert rarity.
 */
class AddSpeciesRarityExclusion extends Migration
{
    /**
     * Add portable exclusion storage with all existing taxa initially included.
     *
     * @return void
     */
    public function up(): void
    {
        if (! $this->forge->addColumn('taxa', [
            'exclude_from_rarity_calculations' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
            ],
            'exclude_from_rarity_calculations_comment' => [
                'type' => 'TEXT',
                'null' => true,
            ],
        ])) {
            throw new \RuntimeException('Species rarity exclusion columns could not be added.');
        }
    }

    /**
     * Remove curated exclusions without changing measured statistics or foreign-key targets.
     *
     * @return void
     */
    public function down(): void
    {
        $legacyAlterTable = null;
        if ($this->db->DBDriver === 'SQLite3') {
            $legacyAlterTable = (int) $this->db->query('PRAGMA legacy_alter_table')->getRowArray()['legacy_alter_table'];
            // Forge rebuilds taxa through a temporary rename; inbound FKs must keep targeting taxa.
            $this->db->query('PRAGMA legacy_alter_table = ON');
        }
        try {
            if (! $this->forge->dropColumn('taxa', [
                'exclude_from_rarity_calculations',
                'exclude_from_rarity_calculations_comment',
            ])) {
                throw new \RuntimeException('Species rarity exclusion columns could not be removed.');
            }
        } finally {
            if ($legacyAlterTable !== null) {
                $this->db->query('PRAGMA legacy_alter_table = ' . $legacyAlterTable);
            }
        }
    }
}
