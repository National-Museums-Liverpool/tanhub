<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds measured occupancy and independent curated rarity decisions to taxon scopes.
 */
class AddTaxonOccupancyAndRarity extends Migration
{
    /**
     * Add nullable measurements and decisions, scheduling existing statistics for rebuilding.
     */
    public function up(): void
    {
        $this->forge->addColumn('taxon_stats', [
            'occupied_square_score' => ['type' => 'DECIMAL', 'constraint' => '7,4', 'null' => true],
            'occupied_square_percentage' => ['type' => 'DECIMAL', 'constraint' => '7,4', 'null' => true],
            'rarity_status' => ['type' => 'VARCHAR', 'constraint' => 6, 'null' => true],
            'rarity_status_comment' => ['type' => 'TEXT', 'null' => true],
            'rarity_status_decision_date' => ['type' => 'DATE', 'null' => true],
        ]);
        $this->db->table('import_offsets')->where('source_key', 'derived-stats:taxon_stats')
            ->update(['next_checkpoint' => null, 'is_complete' => 0]);
    }

    /**
     * Remove occupancy measurements and curated rarity fields.
     */
    public function down(): void
    {
        $this->forge->dropColumn('taxon_stats', [
            'occupied_square_score',
            'occupied_square_percentage',
            'rarity_status',
            'rarity_status_comment',
            'rarity_status_decision_date',
        ]);
    }
}
