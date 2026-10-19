<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds recent yearly average fields to taxon statistics.
 */
class AddTaxonStatsYearlyAverages extends Migration
{
    /**
     * Add zero-initialised yearly average columns.
     *
     * @return void
     */
    public function up(): void
    {
        $this->forge->addColumn('taxon_stats', [
            'mean_squares_per_year' => [
                'type'       => 'DECIMAL',
                'constraint' => '16,4',
                'unsigned'   => true,
                'default'    => 0,
            ],
            'mean_occurrences_per_year' => [
                'type'       => 'DECIMAL',
                'constraint' => '16,4',
                'unsigned'   => true,
                'default'    => 0,
            ],
        ]);
    }

    /**
     * Remove the yearly average columns.
     *
     * @return void
     */
    public function down(): void
    {
        $this->forge->dropColumn('taxon_stats', [
            'mean_squares_per_year',
            'mean_occurrences_per_year',
        ]);
    }
}
