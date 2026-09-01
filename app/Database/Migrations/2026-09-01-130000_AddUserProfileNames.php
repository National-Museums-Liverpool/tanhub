<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds profile name fields to Shield users.
 */
class AddUserProfileNames extends Migration
{
    /**
     * Add first and last name columns to the users table.
     *
     * @return void
     */
    public function up(): void
    {
        $this->forge->addColumn('users', [
            'first_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'last_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
        ]);
    }

    /**
     * Remove the profile name columns.
     *
     * @return void
     */
    public function down(): void
    {
        $this->forge->dropColumn('users', ['first_name', 'last_name']);
    }
}