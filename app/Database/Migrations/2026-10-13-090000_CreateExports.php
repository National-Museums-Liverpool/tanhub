<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Create export checkpoints and immutable, independently sortable captured rows.
 */
class CreateExports extends Migration
{
    /**
     * Install export tables without cascading large staging deletions.
     */
    public function up(): void
    {
        $attributes = $this->db->DBDriver === 'MySQLi' ? ['ENGINE' => 'InnoDB'] : [];
        $this->forge->addField([
            'id' => ['type' => 'BIGINT', 'unsigned' => true, 'auto_increment' => true],
            'uuid' => ['type' => 'CHAR', 'constraint' => 32],
            'owner_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'resource' => ['type' => 'VARCHAR', 'constraint' => 16],
            'contract' => ['type' => 'TEXT'],
            'status' => ['type' => 'VARCHAR', 'constraint' => 16],
            'phase' => ['type' => 'VARCHAR', 'constraint' => 16],
            'max_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'capture_id' => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'captured_rows' => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'payload_bytes' => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'written_rows' => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'file_offset' => ['type' => 'BIGINT', 'unsigned' => true, 'default' => 0],
            'write_cursor' => ['type' => 'TEXT', 'null' => true],
            'staging_cleaned' => ['type' => 'TINYINT', 'default' => 0],
            'files_cleaned' => ['type' => 'TINYINT', 'default' => 0],
            'error' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'progress_at' => ['type' => 'DATETIME'],
            'capture_started_at' => ['type' => 'DATETIME', 'null' => true],
            'capture_finished_at' => ['type' => 'DATETIME', 'null' => true],
            'finished_at' => ['type' => 'DATETIME', 'null' => true],
            'expires_at' => ['type' => 'DATETIME', 'null' => true],
            'delivery_until' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('uuid');
        $this->forge->addKey(['owner_id', 'status']);
        $this->forge->addKey(['status', 'progress_at']);
        $this->forge->addKey('expires_at');
        $this->forge->createTable('export_jobs', true, $attributes);

        $this->forge->addField([
            'job_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'record_id' => ['type' => 'BIGINT', 'unsigned' => true],
            'sort_present' => ['type' => 'TINYINT'],
            'sort_value' => ['type' => 'VARCHAR', 'constraint' => 255],
            'date_present' => ['type' => 'TINYINT'],
            'date_value' => ['type' => 'VARCHAR', 'constraint' => 10],
            'payload' => ['type' => 'TEXT'],
        ]);
        $this->forge->addKey(['job_id', 'record_id'], true);
        $this->forge->addKey(['job_id', 'sort_present', 'sort_value', 'date_present', 'date_value', 'record_id']);
        $this->forge->createTable('export_rows', true, $attributes);
    }

    /**
     * Remove temporary export storage.
     */
    public function down(): void
    {
        $this->forge->dropTable('export_rows', true);
        $this->forge->dropTable('export_jobs', true);
    }
}
