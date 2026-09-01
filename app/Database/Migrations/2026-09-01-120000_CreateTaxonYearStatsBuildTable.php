<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates staging storage for resumable taxon year statistics rebuilds.
 */
class CreateTaxonYearStatsBuildTable extends Migration
{
    /**
     * Apply schema changes.
     */
    public function up(): void
    {
        $this->forge->addField([
            'build_id' => ['type' => 'CHAR', 'constraint' => 36],
            'projection' => ['type' => 'INT', 'constraint' => 4, 'unsigned' => true],
            'uuid' => ['type' => 'CHAR', 'constraint' => 36],
            'taxon_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'geographic_region_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'year' => ['type' => 'INT', 'constraint' => 4, 'unsigned' => true],
            'occurrences_count' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'grid_square_count' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addKey(['build_id', 'projection', 'taxon_id', 'geographic_region_id', 'year'], true);
        $this->forge->addKey(['build_id', 'year']);
        $this->forge->createTable('taxon_year_stats_build', true);
    }

    /**
     * Revert schema changes.
     */
    public function down(): void
    {
        $this->forge->dropTable('taxon_year_stats_build', true);
    }
}
