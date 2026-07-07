<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the taxon_stats processed stats table.
 */
class CreateTaxonStatsTable extends Migration
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
            'occurrences_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'grid_square_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'first_record_date' => [
                'type' => 'DATE',
            ],
            'last_record_date' => [
                'type' => 'DATE',
            ],
            'first_recorder' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'last_recorder' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'first_verified_record_date' => [
                'type' => 'DATE',
            ],
            'last_verified_record_date' => [
                'type' => 'DATE',
            ],
            'first_verified_recorder' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'last_verified_recorder' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('taxon_id');
        $this->forge->addKey('geographic_region_id');
        $this->forge->addForeignKey('taxon_id', 'taxa', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('geographic_region_id', 'geographic_regions', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('taxon_stats', true);
    }

    /**
     * Revert schema changes.
     */
    public function down(): void
    {
        $this->forge->dropTable('taxon_stats', true);
    }
}
