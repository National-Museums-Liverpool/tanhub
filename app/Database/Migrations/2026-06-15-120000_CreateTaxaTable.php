<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\RawSql;
use CodeIgniter\Database\Migration;

/**
 * Creates the taxa table with taxonomy relationships.
 */
class CreateTaxaTable extends Migration
{
    /**
     * Apply schema changes.
     */
    public function up(): void
    {
        $ranks = config('Import')->taxonRanks;
        $rankColumns = array_map(fn ($rank) => strtolower($rank) . '_id', $ranks);

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'taxon_identifier' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'scientific_name_identifier' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'scientific_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 200,
            ],
            'scientific_name_authorship' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'vernacular_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 200,
            ],
            'taxon_rank_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
            ],
        ]);

        // Dynamically add fields for each taxon rank based on the
        // configuration.
        foreach ($rankColumns as $rankColumn) {
            $this->forge->addField([
                $rankColumn => [
                    'type'       => 'BIGINT',
                    'constraint' => 20,
                    'unsigned'   => true,
                    'null'       => true,
                ],
            ]);
        }
        $this->forge->addField([
            'taxon_group_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
            ],
            'id_difficulty' => [
                'type'       => 'TINYINT',
                'constraint' => 3,
                'null'       => true,
            ],
            'recording_scheme_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],
            'conservation_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
                'null'       => true,
            ],
            'taxon_remarks' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'rarity_group_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'blocked' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'blocked_reason' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => false,
                'default' => new RawSql('CURRENT_TIMESTAMP'),
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'deleted_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('taxon_identifier');
        $this->forge->addKey('scientific_name_identifier');
        $this->forge->addKey('taxon_rank_id');
        $this->forge->addKey('taxon_group_id');
        $this->forge->addKey('recording_scheme_id');
        foreach ($rankColumns as $rankColumn) {
            $this->forge->addKey($rankColumn);
        }
        $this->forge->addForeignKey('taxon_rank_id', 'taxon_ranks', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('taxon_group_id', 'taxon_groups', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('recording_scheme_id', 'recording_schemes', 'id', 'CASCADE', 'SET NULL');
        // Define self-referential rank FKs as part of CREATE TABLE (works with SQLite).
        foreach ($rankColumns as $rankColumn) {
            $this->forge->addForeignKey($rankColumn, 'taxa', 'id', 'CASCADE', 'SET NULL');
        }
        $this->forge->createTable('taxa', true);
    }

    /**
     * Revert schema changes.
     */
    public function down(): void
    {
        $this->forge->dropTable('taxa', true);
    }

}
