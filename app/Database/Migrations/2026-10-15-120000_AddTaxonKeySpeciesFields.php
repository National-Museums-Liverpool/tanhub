<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds curated key-species fields to taxon statistics.
 */
class AddTaxonKeySpeciesFields extends Migration
{
    /**
     * Add nullable key-species decision, rationale, and decision-date fields.
     *
     * @return void
     */
    public function up(): void
    {
        $this->forge->addColumn('taxon_stats', [
            'key_species' => [
                'type'    => 'BOOLEAN',
                'null'    => true,
                'default' => null,
            ],
            'key_species_comment' => [
                'type'    => 'TEXT',
                'null'    => true,
                'default' => null,
            ],
            'key_species_decision_date' => [
                'type'    => 'DATE',
                'null'    => true,
                'default' => null,
            ],
        ]);
    }

    /**
     * Remove curated key-species fields.
     *
     * @return void
     */
    public function down(): void
    {
        $this->forge->dropColumn('taxon_stats', [
            'key_species',
            'key_species_comment',
            'key_species_decision_date',
        ]);
    }
}
