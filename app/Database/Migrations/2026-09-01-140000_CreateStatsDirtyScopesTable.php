<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Creates the deduplicated queue of statistic scopes affected by occurrence changes.
 */
class CreateStatsDirtyScopesTable extends Migration
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
            'scope_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 180,
            ],
            'stat_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'projection' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'taxon_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
            ],
            'geographic_region_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],
            'year' => [
                'type'       => 'INT',
                'constraint' => 4,
                'unsigned'   => true,
                'null'       => true,
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
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('scope_key');
        $this->forge->addKey(['stat_type', 'id']);
        $this->forge->addKey(['stat_type', 'taxon_id', 'geographic_region_id', 'year']);
        $this->forge->createTable('stats_dirty_scopes', true);
    }

    /**
     * Revert schema changes.
     */
    public function down(): void
    {
        $this->forge->dropTable('stats_dirty_scopes', true);
    }
}