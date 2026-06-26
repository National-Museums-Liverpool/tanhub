<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\RawSql;
use CodeIgniter\Database\Migration;

class CreateTaxaTable extends Migration
{
    public function up(): void
    {
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
            'order_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
            ],
            'superfamily_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],
            'family_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
            ],
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
        $this->forge->addKey('order_id');
        $this->forge->addKey('superfamily_id');
        $this->forge->addKey('family_id');
        $this->forge->addKey('taxon_group_id');
        $this->forge->addKey('recording_scheme_id');

        $this->forge->addForeignKey('order_id', 'orders', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('superfamily_id', 'superfamilies', 'id', 'CASCADE', 'SET NULL');
        $this->forge->addForeignKey('family_id', 'families', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('taxon_group_id', 'taxon_groups', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('recording_scheme_id', 'recording_schemes', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('taxa', true);
    }

    public function down(): void
    {
        $this->forge->dropTable('taxa', true);
    }
}
