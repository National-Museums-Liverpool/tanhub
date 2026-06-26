<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the data sources lookup table.
 */
class CreateDataSourcesTable extends Migration
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
            'abbr' => [
                'type'       => 'VARCHAR',
                'constraint' => 10,
            ],
            'title' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'url' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('abbr');
        $this->forge->addUniqueKey('title');
        $this->forge->createTable('data_sources', true);
    }

    /**
     * Revert schema changes.
     */
    public function down(): void
    {
        $this->forge->dropTable('data_sources', true);
    }
}
