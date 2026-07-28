<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Creates the import task queue table.
 */
class CreateImportTaskQueueTable extends Migration
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
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'source_key' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
            ],
            'run_id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'null' => true,
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'queued',
            ],
            'queued_at' => [
                'type' => 'DATETIME',
                'null' => false,
                'default' => new RawSql('CURRENT_TIMESTAMP'),
            ],
            'started_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'finished_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
                'default' => new RawSql('CURRENT_TIMESTAMP'),
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['status', 'queued_at']);
        $this->forge->addKey('source_key');
        $this->forge->addKey('run_id');
        $this->forge->addForeignKey('run_id', 'import_runs', 'id', 'SET NULL', 'SET NULL');
        $this->forge->createTable('import_task_queue', true);
    }

    /**
     * Revert schema changes.
     *
     * @return void
     */
    public function down(): void
    {
        $this->forge->dropTable('import_task_queue', true);
    }
}
