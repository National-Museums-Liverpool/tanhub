<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the grid_square_stats processed stats table.
 */
class CreateGridSquareStatsTable extends Migration
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
            'square' => [
                'type'       => 'VARCHAR',
                'constraint' => 12,
            ],
            'geographic_region_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
                'null'       => true,
            ],
            'easting' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'northing' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
            ],
            'partial' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'occurrences_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'default'    => 0,
            ],
            'species_count' => [
                'type'       => 'INT',
                'constraint' => 11,
                'unsigned'   => true,
                'default'    => 0,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['square', 'geographic_region_id']);
        $this->forge->addKey('square');
        $this->forge->addKey('geographic_region_id');
        $this->forge->addKey('partial');
        $this->forge->addForeignKey('geographic_region_id', 'geographic_regions', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('grid_square_stats', true);
    }

    /**
     * Revert schema changes.
     */
    public function down(): void
    {
        $this->forge->dropTable('grid_square_stats', true);
    }
}
