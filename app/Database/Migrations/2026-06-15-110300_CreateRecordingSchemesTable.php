<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Creates the recording schemes lookup table.
 */
class CreateRecordingSchemesTable extends Migration
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
            'external_key' => [
                'type'       => 'CHAR',
                'constraint' => 16,
            ],
            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
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
        $this->forge->addUniqueKey('external_key');
        $this->forge->addKey('title');
        $this->forge->createTable('recording_schemes', true);
    }

    /**
     * Revert schema changes.
     */
    public function down(): void
    {
        $this->forge->dropTable('recording_schemes', true);
    }
}
