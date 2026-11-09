<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Store configurable typed taxon attributes independently of client-specific terminology.
 */
class CreateTaxonAttributes extends Migration
{
    /**
     * Create definitions, attribute-scoped terms and indexed typed assignments.
     */
    public function up(): void
    {
        $attributes = $this->db->DBDriver === 'MySQLi' ? ['ENGINE' => 'InnoDB'] : [];
        $id = ['type' => 'BIGINT', 'unsigned' => true];
        $this->forge->addField([
            'id' => $id + ['auto_increment' => true],
            'attribute_key' => ['type' => 'VARCHAR', 'constraint' => 63],
            'source_id' => $id,
            'label' => ['type' => 'VARCHAR', 'constraint' => 200],
            'data_type' => ['type' => 'VARCHAR', 'constraint' => 10],
            'multi_value' => ['type' => 'BOOLEAN'],
            'termlist_id' => $id + ['null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('attribute_key');
        $this->forge->addUniqueKey('source_id');
        $this->forge->createTable('taxon_attributes', true, $attributes);

        $this->forge->addField([
            'id' => $id + ['auto_increment' => true],
            'attribute_id' => $id,
            'source_id' => $id,
            'term_key' => ['type' => 'VARCHAR', 'constraint' => 40],
            'label' => ['type' => 'VARCHAR', 'constraint' => 255],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['attribute_id', 'source_id'], 'attr_term_source');
        $this->forge->addUniqueKey(['attribute_id', 'term_key'], 'attr_term_key');
        $this->forge->addUniqueKey(['attribute_id', 'id'], 'attr_term_owner');
        $this->forge->addForeignKey('attribute_id', 'taxon_attributes', 'id', 'CASCADE', 'CASCADE',
            $this->foreignKeyName('attr_term_definition_fk'));
        $this->forge->createTable('taxon_attribute_terms', true, $attributes);

        $this->forge->addField([
            'id' => $id + ['auto_increment' => true],
            'taxon_id' => $id,
            'attribute_id' => $id,
            'integer_value' => ['type' => 'BIGINT', 'null' => true],
            'term_id' => $id + ['null' => true],
        ]);
        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey(['taxon_id', 'attribute_id', 'integer_value'], 'attr_value_integer');
        $this->forge->addUniqueKey(['taxon_id', 'attribute_id', 'term_id'], 'attr_value_term');
        $this->forge->addKey(['attribute_id', 'integer_value', 'taxon_id'], false, false, 'attr_integer_taxon');
        $this->forge->addKey(['attribute_id', 'term_id', 'taxon_id'], false, false, 'attr_term_taxon');
        $this->forge->addForeignKey('taxon_id', 'taxa', 'id', 'CASCADE', 'CASCADE',
            $this->foreignKeyName('attr_value_taxon_fk'));
        $this->forge->addForeignKey('attribute_id', 'taxon_attributes', 'id', 'CASCADE', 'CASCADE',
            $this->foreignKeyName('attr_value_definition_fk'));
        // Both definition cascade paths must remove values, regardless of InnoDB traversal order.
        $this->forge->addForeignKey(['attribute_id', 'term_id'], 'taxon_attribute_terms', ['attribute_id', 'id'],
            '', 'CASCADE', $this->foreignKeyName('attr_value_term_fk'));
        $this->forge->addField('CHECK ((integer_value IS NOT NULL AND term_id IS NULL)'
            . ' OR (integer_value IS NULL AND term_id IS NOT NULL))');
        $this->forge->createTable('taxon_attribute_values', true, $attributes);
    }

    /**
     * Remove assignments before their referenced dictionaries.
     */
    public function down(): void
    {
        $this->forge->dropTable('taxon_attribute_values', true);
        $this->forge->dropTable('taxon_attribute_terms', true);
        $this->forge->dropTable('taxon_attributes', true);
    }

    /**
     * Keep native names short while respecting Forge's unnamed SQLite foreign keys.
     *
     * @param string $name Compact unprefixed constraint name.
     * @return string Deployment-scoped name, or empty for SQLite's generated identifiers.
     */
    private function foreignKeyName(string $name): string
    {
        return $this->db->DBDriver === 'SQLite3' ? '' : $this->db->DBPrefix . $name;
    }
}
