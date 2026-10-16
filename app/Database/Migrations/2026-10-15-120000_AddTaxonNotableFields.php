<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds curated key-species decisions to taxon statistics.
 */
class AddTaxonNotableFields extends Migration
{
    /**
     * Add nullable decision, rationale, and decision-date fields.
     *
     * @return void
     */
    public function up(): void
    {
        $this->forge->addColumn('taxon_stats', [
            'notable' => [
                'type'    => 'BOOLEAN',
                'null'    => true,
                'default' => null,
            ],
            'notable_comment' => [
                'type'    => 'TEXT',
                'null'    => true,
                'default' => null,
            ],
            'notable_decision_date' => [
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
            'notable',
            'notable_comment',
            'notable_decision_date',
        ]);
    }
}
