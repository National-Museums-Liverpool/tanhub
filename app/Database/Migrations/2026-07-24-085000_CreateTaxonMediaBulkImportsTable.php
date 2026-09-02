<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Creates bulk taxon media import headers.
 */
class CreateTaxonMediaBulkImportsTable extends Migration
{
    /**
     * Apply schema changes.
     *
     * @return void
     */
    public function up(): void
    {
        $this->forge->addField([
            'id' => [
                'type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true,
            ],
            'uuid' => ['type' => 'CHAR', 'constraint' => 36],
            'owner_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'csv_filename' => ['type' => 'VARCHAR', 'constraint' => 255],
            'csv_path' => ['type' => 'VARCHAR', 'constraint' => 500],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'queued'],
            'total_rows' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'default' => 0],
            'processed_rows' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'default' => 0],
            'next_row_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'error_message' => ['type' => 'TEXT', 'null' => true],
            'validation_report' => ['type' => 'TEXT', 'null' => true],
            'heartbeat_at' => ['type' => 'DATETIME', 'null' => true],
            'started_at' => ['type' => 'DATETIME', 'null' => true],
            'finished_at' => ['type' => 'DATETIME', 'null' => true],
            'created_at' => [
                'type' => 'DATETIME', 'null' => false, 'default' => new RawSql('CURRENT_TIMESTAMP'),
            ],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey(['owner_user_id', 'status']);
        $this->forge->addKey(['status', 'next_row_id']);
        $this->forge->addForeignKey('owner_user_id', 'users', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('taxon_media_bulk_imports', true);
    }

    /**
     * Revert schema changes.
     *
     * @return void
     */
    public function down(): void
    {
        $this->forge->dropTable('taxon_media_bulk_imports', true);
    }
}