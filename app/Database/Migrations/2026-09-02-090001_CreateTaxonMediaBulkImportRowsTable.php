<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Creates staged rows for bulk taxon media imports.
 */
class CreateTaxonMediaBulkImportRowsTable extends Migration
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
            'import_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'row_number' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true],
            'taxon_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'photo_filename' => ['type' => 'VARCHAR', 'constraint' => 255],
            'staged_path' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'mime_type' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'staged_bytes' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
            'checksum' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'alt_text' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'caption' => ['type' => 'TEXT', 'null' => true],
            'attribution' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'license' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'sort_order' => ['type' => 'INT', 'constraint' => 10, 'unsigned' => true, 'default' => 0],
            'is_primary' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'status' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'pending'],
            'media_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'error_message' => ['type' => 'TEXT', 'null' => true],
            'created_at' => [
                'type' => 'DATETIME', 'null' => false, 'default' => new RawSql('CURRENT_TIMESTAMP'),
            ],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['import_id', 'row_number']);
        $this->forge->addKey(['import_id', 'status', 'id']);
        $this->forge->addForeignKey('import_id', 'taxon_media_bulk_imports', 'id', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('taxon_id', 'taxa', 'id', 'CASCADE', 'RESTRICT');
        $this->forge->createTable('taxon_media_bulk_import_rows', true);
    }

    /**
     * Revert schema changes.
     *
     * @return void
     */
    public function down(): void
    {
        $this->forge->dropTable('taxon_media_bulk_import_rows', true);
    }
}