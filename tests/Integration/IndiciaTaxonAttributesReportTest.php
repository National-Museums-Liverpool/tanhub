<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;
use PDO;

/**
 * Execute the real warehouse report against disposable PostgreSQL temporary fixtures.
 *
 * @internal
 */
final class IndiciaTaxonAttributesReportTest extends CIUnitTestCase
{
    /**
     * Verify synonym union and singleton selection occur before taxon pagination.
     */
    public function testReportPreservesTaxonCardinalityAndSelectsSingletons(): void
    {
        $dsn = getenv('TANHUB_ATTRIBUTE_PG_DSN');
        if (! is_string($dsn) || $dsn === '') {
            $this->markTestSkipped('Set TANHUB_ATTRIBUTE_PG_DSN to an isolated *_attributes_validation database.');
        }
        $db = new PDO($dsn, getenv('TANHUB_ATTRIBUTE_PG_USER') ?: null,
            getenv('TANHUB_ATTRIBUTE_PG_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database = $db->query('SELECT current_database()')->fetchColumn();
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_]+_attributes_validation$/D', $database);
        $db->beginTransaction();
        try {
            $tables = [
                'cache_taxa_taxon_lists' => 'id int, taxon_meaning_id int, taxon_list_id int,'
                    . ' preferred boolean, allow_data_entry boolean, taxon_id int, taxon_group_id int,'
                    . ' taxon_rank_sort_order int, organism_key text',
                'cache_taxon_paths' => 'taxon_meaning_id int, taxon_list_id int, path int[]',
                'taxon_groups' => 'id int, deleted boolean',
                'cache_taxon_searchterms' => 'taxa_taxon_list_id int, simplified boolean',
                'recording_scheme_taxa' => 'organism_key text, deleted boolean, recording_scheme_id int',
                'recording_schemes' => 'id int, deleted boolean',
                'taxa_taxon_designations' => 'taxon_id int, taxon_designation_id int, deleted boolean',
                'taxon_designations' => 'id int, category_id int, deleted boolean, abbreviation text',
                'cache_termlists_terms' => 'id int, term text',
                'taxa_taxon_list_attributes' => 'id int, data_type text, multi_value boolean, termlist_id int, deleted boolean',
                'taxa_taxon_list_attribute_values' => 'id int, taxa_taxon_list_id int,'
                    . ' taxa_taxon_list_attribute_id int, int_value int, deleted boolean',
            ];
            foreach ($tables as $table => $columns) {
                $db->exec('CREATE TEMPORARY TABLE ' . $table . ' (' . $columns . ') ON COMMIT DROP');
            }
            $db->exec("INSERT INTO cache_taxa_taxon_lists VALUES
                (1,1,1,true,true,1,1,300,'TAX-1'),
                (2,1,1,false,true,2,1,300,'TAX-1'),
                (3,2,1,true,true,3,1,300,'TAX-2'),
                (4,1,2,true,true,4,1,300,'OTHER-LIST')");
            $db->exec("INSERT INTO cache_taxon_paths VALUES (1,1,'{1}'),(2,1,'{2}')");
            $db->exec('INSERT INTO taxon_groups VALUES (1,false)');
            $db->exec('INSERT INTO cache_taxon_searchterms VALUES (1,true),(3,true)');
            $db->exec("INSERT INTO taxa_taxon_list_attributes VALUES
                (15,'L',true,627,false),(16,'L',true,627,false),
                (20,'L',false,627,false),(24,'I',false,NULL,false)");
            $db->exec("INSERT INTO cache_termlists_terms VALUES
                (101,'open habitats'),(102,'tree-associated'),(103,'shaded woodland floor'),
                (104,'tall sward & scrub'),(105,'SAT A'),(106,'SAT B')");
            $db->exec('INSERT INTO taxa_taxon_list_attribute_values VALUES
                (1,1,15,101,false),(2,2,15,102,false),(3,2,15,101,false),
                (4,1,16,103,false),(5,2,16,104,false),
                (6,2,20,105,false),(7,1,20,106,false),
                (8,2,24,1,false),(9,1,24,9,false),(10,4,24,99,false),
                (11,1,24,88,true)');
            $xml = simplexml_load_file(ROOTPATH . 'indicia-warehouse-module/tanhub/reports/projects/tanhub/taxa.xml');
            $query = strtr((string) $xml->query, [
                '#columns#' => 'cttl.id, attributes.payload AS taxon_attributes',
                '#taxon_list_id#' => '1', '#filters#' => '', '#taxon_attribute_ids#' => '15,16,20,24',
            ]) . ' ORDER BY cttl.id';
            $rows = $db->query($query)->fetchAll(PDO::FETCH_ASSOC);
            $this->assertCount(2, $rows);
            $payload = json_decode($rows[0]['taxon_attributes'], true, 512, JSON_THROW_ON_ERROR);
            $attributes = array_column($payload['attributes'], null, 'id');
            $this->assertSame([105], array_column($attributes[20]['values'], 'value'));
            $this->assertSame([1], array_column($attributes[24]['values'], 'value'));
            $this->assertCount(2, $attributes[15]['values']);
            $this->assertCount(2, $attributes[16]['values']);
            $page = $db->query($query . ' LIMIT 1 OFFSET 1')->fetchAll(PDO::FETCH_ASSOC);
            $this->assertSame('3', (string) $page[0]['id']);
            $empty = json_decode($page[0]['taxon_attributes'], true, 512, JSON_THROW_ON_ERROR);
            foreach ($empty['attributes'] as $attribute) {
                $this->assertSame([], $attribute['values']);
            }
            $metadataXml = simplexml_load_file(ROOTPATH . 'indicia-warehouse-module/tanhub/reports/projects/tanhub/taxon_attributes.xml');
            $metadataQuery = strtr((string) $metadataXml->query, [
                '#columns#' => (string) $metadataXml->columns->column['sql'],
                '#taxon_attribute_ids#' => '15,16,20,24',
            ]);
            $metadata = json_decode($db->query($metadataQuery)->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertCount(4, $metadata['attributes']);
            $this->assertSame([15 => true, 16 => true, 20 => false, 24 => false],
                array_column($metadata['attributes'], 'multi_value', 'id'));
        } finally {
            $db->rollBack();
        }
    }
}
