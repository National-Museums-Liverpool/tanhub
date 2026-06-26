<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Creates the superfamilies lookup table.
 */
class CreateSuperfamiliesTable extends Migration
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
        $this->forge->addKey('scientific_name');
        $this->forge->createTable('superfamilies', true);
    }

    /**
     * Revert schema changes.
     */
    public function down(): void
    {
        $this->forge->dropTable('superfamilies', true);
    }
}
