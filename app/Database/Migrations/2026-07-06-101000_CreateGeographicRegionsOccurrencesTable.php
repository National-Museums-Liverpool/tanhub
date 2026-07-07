<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Creates the geographic regions to occurrences join table.
 */
class CreateGeographicRegionsOccurrencesTable extends Migration
{
    /**
     * Apply schema changes.
     */
    public function up(): void
    {
        $this->forge->addField([
            'geographic_region_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
            ],
            'occurrence_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
            ],
        ]);

        $this->forge->addKey(['geographic_region_id', 'occurrence_id'], true);
        $this->forge->addKey('occurrence_id');
        $this->forge->addForeignKey('geographic_region_id', 'geographic_regions', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('occurrence_id', 'occurrences', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('geographic_regions_occurrences', true);
    }

    /**
     * Revert schema changes.
     */
    public function down(): void
    {
        $this->forge->dropTable('geographic_regions_occurrences', true);
    }
}
