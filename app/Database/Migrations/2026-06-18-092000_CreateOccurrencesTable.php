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

        $this->forge->addForeignKey('taxon_id', 'taxa', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('taxon_name_id', 'taxon_names', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('data_source_id', 'data_sources', 'id', 'CASCADE', 'RESTRICT');
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
