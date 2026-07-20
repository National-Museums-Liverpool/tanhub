<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Creates the occurrences table.
 */
class CreateOccurrencesTable extends Migration
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
            'unique_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'taxon_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
            ],
            'taxon_name_id' => [
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
            'from_date' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'to_date' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'grid_ref' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
            ],
            'grid_ref_2km' => [
                'type'       => 'CHAR',
                'constraint' => 5,
                'null'       => true,
            ],
            'locality' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'recorded_by' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'identified_by' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'identification_verification_status' => [
                'type'       => 'VARCHAR',
                'constraint' => 2,
            ],
            'sex' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
            ],
            'life_stage' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
            ],
            'organism_quantity' => [
                'type'       => 'VARCHAR',
                'constraint' => 20,
                'null'       => true,
            ],
            'data_source_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
            ],
            'latitude' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,7',
                'null'       => true,
            ],
            'longitude' => [
                'type'       => 'DECIMAL',
                'constraint' => '10,7',
                'null'       => true,
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
        $this->forge->addUniqueKey('unique_key');
        $this->forge->addKey('taxon_id');
        $this->forge->addKey('taxon_name_id');
        $this->forge->addKey('data_source_id');
        $this->forge->addKey('from_date');
        $this->forge->addKey('to_date');
        $this->forge->addKey('grid_ref_2km');
        foreach ($rankColumns as $rankColumn) {
            $this->forge->addKey($rankColumn);
        }

        $this->forge->addForeignKey('taxon_id', 'taxa', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('taxon_name_id', 'taxon_names', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('data_source_id', 'data_sources', 'id', 'CASCADE', 'RESTRICT');
        // Define self-referential rank FKs as part of CREATE TABLE (works with SQLite).
        foreach ($rankColumns as $rankColumn) {
            $this->forge->addForeignKey($rankColumn, 'taxa', 'id', 'CASCADE', 'SET NULL');
        }
        $this->forge->createTable('occurrences', true);
    }

    /**
     * Revert schema changes.
     */
    public function down(): void
    {
        $this->forge->dropTable('occurrences', true);
    }
}
