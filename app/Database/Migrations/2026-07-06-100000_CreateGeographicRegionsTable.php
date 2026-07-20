<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Creates the geographic regions lookup table.
 */
class CreateGeographicRegionsTable extends Migration
{
    /**
     * Apply schema changes.
     */
    public function up(): void
    {
        $isSqlite = strtoupper((string) ($this->db->DBDriver ?? '')) === 'SQLITE3';

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'higher_geography_identifier' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'higher_geography' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'location_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'footprint_geometry' => [
                'type'       => $isSqlite ? 'TEXT' : 'GEOMETRY',
                'null'       => true,
            ],
            'data_source_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
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
        $this->forge->addUniqueKey('higher_geography_identifier');
        $this->forge->addKey('higher_geography');
        $this->forge->addKey('location_type');
        $this->forge->addKey('data_source_id');
        $this->forge->addForeignKey('data_source_id', 'data_sources', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('geographic_regions', true);
    }

    /**
     * Revert schema changes.
     */
    public function down(): void
    {
        $this->forge->dropTable('geographic_regions', true);
    }
}
