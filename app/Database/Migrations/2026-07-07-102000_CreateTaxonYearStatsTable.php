<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the taxon_year_stats processed stats table.
 */
class CreateTaxonYearStatsTable extends Migration
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
            'year' => [
                'type'       => 'INT',
                'constraint' => 4,
                'unsigned'   => true,
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
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('taxon_id');
        $this->forge->addKey('geographic_region_id');
        $this->forge->addKey('year');
        $this->forge->addForeignKey('taxon_id', 'taxa', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->addForeignKey('geographic_region_id', 'geographic_regions', 'id', 'CASCADE', 'SET NULL');
        $this->forge->createTable('taxon_year_stats', true);
    }

    /**
     * Revert schema changes.
     */
    public function down(): void
    {
        $this->forge->dropTable('taxon_year_stats', true);
    }
}
