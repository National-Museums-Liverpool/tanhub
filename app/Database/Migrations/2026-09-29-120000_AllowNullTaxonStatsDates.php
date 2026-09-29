<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Allows taxon statistics date fields to represent unknown dates.
 */
class AllowNullTaxonStatsDates extends Migration
{
    /**
     * Make aggregate date fields nullable for undated occurrences.
     *
     * @return void
     */
    public function up(): void
    {
        $this->forge->modifyColumn('taxon_stats', [
            'first_record_date' => [
                'type'    => 'DATE',
                'null'    => true,
                'default' => null,
            ],
            'last_record_date' => [
                'type'    => 'DATE',
                'null'    => true,
                'default' => null,
            ],
            'first_verified_record_date' => [
                'type'    => 'DATE',
                'null'    => true,
                'default' => null,
            ],
            'last_verified_record_date' => [
                'type'    => 'DATE',
                'null'    => true,
                'default' => null,
            ],
        ]);
    }

    /**
     * Restore the original non-null date constraints.
     *
     * @return void
     */
    public function down(): void
    {
        $this->forge->modifyColumn('taxon_stats', [
            'first_record_date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'last_record_date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'first_verified_record_date' => [
                'type' => 'DATE',
                'null' => false,
            ],
            'last_verified_record_date' => [
                'type' => 'DATE',
                'null' => false,
            ],
        ]);
    }
}
