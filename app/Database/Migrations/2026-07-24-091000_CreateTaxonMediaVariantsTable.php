<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use CodeIgniter\Database\RawSql;

/**
 * Creates the taxon media variants table.
 */
class CreateTaxonMediaVariantsTable extends Migration
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
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'taxon_media_id' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
            ],
            'variant_key' => [
                'type'       => 'VARCHAR',
                'constraint' => 50,
            ],
            'storage_path' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
            ],
            'mime_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
            ],
            'bytes' => [
                'type'       => 'BIGINT',
                'constraint' => 20,
                'unsigned'   => true,
            ],
            'width' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'height' => [
                'type'       => 'INT',
                'constraint' => 10,
                'unsigned'   => true,
                'null'       => true,
            ],
            'created_at' => [
                'type'    => 'DATETIME',
                'null'    => false,
                'default' => new RawSql('CURRENT_TIMESTAMP'),
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('taxon_media_id');
        $this->forge->addUniqueKey(['taxon_media_id', 'variant_key']);

        $this->forge->addForeignKey('taxon_media_id', 'taxon_media', 'id', 'CASCADE', 'CASCADE');
        $this->forge->createTable('taxon_media_variants', true);
    }

    /**
     * Revert schema changes.
      *
      * @return void
     */
    public function down(): void
    {
        $this->forge->dropTable('taxon_media_variants', true);
    }
}
