<?php

namespace Tests;

use App\Database\Migrations\CreateTaxonAttributes;
use App\Services\TaxonAttributeService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\TaxonAttributes;

/**
 * Validate production-driver foreign keys and native controlled-label collation in isolation.
 *
 * @internal
 */
final class TaxonAttributeMySqlTest extends CIUnitTestCase
{
    /**
     * Compare aggregate ordering with case/accent-equivalent labels and absent assignments.
     */
    public function testNativeLabelOrderingAndMissingValues(): void
    {
        $db = $this->validationConnection();
        $forge = Database::forge($db);
        $migration = null;
        $taxaCreated = false;
        try {
            $actualDatabase = $db->query('SELECT DATABASE() AS validation_database')->getRowArray();
            $this->assertSame($db->getDatabase(), $actualDatabase['validation_database']);
            $forge->addField([
                'id' => ['type' => 'BIGINT', 'unsigned' => true],
                'blocked' => ['type' => 'BOOLEAN', 'default' => 0],
                'deleted_at' => ['type' => 'DATETIME', 'null' => true],
            ]);
            $forge->addKey('id', true);
            $forge->createTable('taxa', false, ['ENGINE' => 'InnoDB']);
            $taxaCreated = true;
            require_once APPPATH . 'Database/Migrations/2026-11-09-100000_CreateTaxonAttributes.php';
            $migration = new CreateTaxonAttributes($forge);
            $migration->up();
            $config = new TaxonAttributes();
            $config->attributes = [
                'habitat' => ['id' => 16, 'label' => 'Habitat', 'type' => 'term', 'multiValue' => true],
            ];
            $service = new TaxonAttributeService($db, $config);
            // Unicode is intentional: this checks the actual database's accent/case collation.
            foreach ([1 => ['Éclair', 'Zulu'], 2 => ['eclair'], 3 => ['Alpha'], 4 => ['Zulu'], 5 => []] as $id => $labels) {
                $db->table('taxa')->insert(['id' => $id]);
                $values = [];
                foreach ($labels as $index => $label) {
                    $values[] = ['value' => $id * 10 + $index, 'label' => $label];
                }
                $db->transBegin();
                $service->replace($id, ['habitat' => ['termlist_id' => 627, 'values' => $values]]);
                $this->assertTrue($db->transCommit());
            }
            foreach (['ASC' => [3, 1, 2, 4, 5], 'DESC' => [1, 4, 2, 3, 5]] as $direction => $expected) {
                $expression = $service->sortExpression('habitat', $direction);
                $rows = $db->table('taxa t')->select('t.id')
                    ->orderBy('(' . $expression . ' IS NULL)', 'ASC', false)
                    ->orderBy($expression, $direction, false)->orderBy('t.id')->get()->getResultArray();
                $this->assertSame($expected, array_map('intval', array_column($rows, 'id')));
            }
            $this->assertTrue($db->table('taxa')->where('id', 1)->delete());
            $this->assertSame(0, $db->table('taxon_attribute_values')->where('taxon_id', 1)->countAllResults());
            $this->assertTrue($db->table('taxon_attributes')->where('attribute_key', 'habitat')->delete());
            $this->assertSame(0, $db->table('taxon_attribute_values')->countAllResults());
            $this->assertSame(0, $db->table('taxon_attribute_terms')->countAllResults());
        } finally {
            if ($migration !== null) {
                $migration->down();
            }
            if ($taxaCreated) {
                $forge->dropTable('taxa', true);
            }
            $db->close();
        }
    }

    /**
     * Exercise bounded query/hydration paths at deployment-like cardinalities, not HTTP latency.
     *
     * This opt-in diagnostic deliberately reports measurements without certifying a hosting budget.
     */
    public function testScaledAttributeQueries(): void
    {
        if (getenv('TANHUB_ATTRIBUTE_MYSQL_SCALE') !== '1') {
            $this->markTestSkipped('Set TANHUB_ATTRIBUTE_MYSQL_SCALE=1 for the large synthetic benchmark.');
        }
        $db = $this->validationConnection();
        $forge = Database::forge($db);
        $migration = null;
        $created = [];
        try {
            $this->assertSame($db->getDatabase(), $db->query('SELECT DATABASE() AS name')->getRowArray()['name']);
            foreach ([
                'digits' => 'n TINYINT PRIMARY KEY',
                'taxa' => 'id BIGINT UNSIGNED PRIMARY KEY, blocked BOOLEAN NOT NULL DEFAULT 0, deleted_at DATETIME NULL',
                'occurrences' => 'id BIGINT UNSIGNED PRIMARY KEY, taxon_id BIGINT UNSIGNED NOT NULL, KEY (taxon_id)',
            ] as $table => $columns) {
                $db->query('CREATE TABLE ' . $db->escapeIdentifiers($db->prefixTable($table))
                    . ' (' . $columns . ') ENGINE=InnoDB');
                $created[] = $table;
            }
            require_once APPPATH . 'Database/Migrations/2026-11-09-100000_CreateTaxonAttributes.php';
            $migration = new CreateTaxonAttributes($forge);
            $migration->up();
            $config = new TaxonAttributes();
            $config->attributes = [
                'sqs' => ['id' => 24, 'label' => 'SQS', 'type' => 'integer', 'multiValue' => false],
                'broad' => ['id' => 15, 'label' => 'Broad', 'type' => 'term', 'multiValue' => true],
                'habitat' => ['id' => 16, 'label' => 'Habitat', 'type' => 'term', 'multiValue' => true],
                'sat' => ['id' => 20, 'label' => 'SAT', 'type' => 'term', 'multiValue' => false],
            ];
            $service = new TaxonAttributeService($db, $config);
            $service->synchronizeDefinitions();
            $digits = $db->escapeIdentifiers($db->prefixTable('digits'));
            $taxa = $db->escapeIdentifiers($db->prefixTable('taxa'));
            $occurrences = $db->escapeIdentifiers($db->prefixTable('occurrences'));
            $terms = $db->escapeIdentifiers($db->prefixTable('taxon_attribute_terms'));
            $values = $db->escapeIdentifiers($db->prefixTable('taxon_attribute_values'));
            $db->query("INSERT INTO {$digits} VALUES (0),(1),(2),(3),(4),(5),(6),(7),(8),(9)");
            $db->query("INSERT INTO {$taxa} (id) SELECT a.n+b.n*10+c.n*100+d.n*1000+e.n*10000+1"
                . " FROM {$digits} a CROSS JOIN {$digits} b CROSS JOIN {$digits} c"
                . " CROSS JOIN {$digits} d CROSS JOIN {$digits} e");
            $db->query("INSERT INTO {$occurrences} SELECT (t.id-1)*20+a.n+b.n*10+1,t.id"
                . " FROM {$taxa} t CROSS JOIN {$digits} a CROSS JOIN {$digits} b WHERE b.n<2");
            foreach ($service->registry() as $key => $definition) {
                $attributeId = (int) $definition['id'];
                if ($key === 'sqs') {
                    $db->query("INSERT INTO {$values} (taxon_id,attribute_id,integer_value)"
                        . " SELECT id,{$attributeId},MOD(id,21) FROM {$taxa}");
                    continue;
                }
                $db->query("INSERT INTO {$terms} (attribute_id,source_id,term_key,label)"
                    . " SELECT {$attributeId},a.n+b.n*10+1,CONCAT('indicia:',a.n+b.n*10+1),"
                    . " CONCAT('Habitat ',LPAD(a.n+b.n*10,3,'0')) FROM {$digits} a CROSS JOIN {$digits} b");
                $count = $key === 'sat' ? 1 : 4;
                $db->query("INSERT INTO {$values} (taxon_id,attribute_id,term_id)"
                    . " SELECT t.id,{$attributeId},atr.id FROM {$taxa} t CROSS JOIN {$digits} d"
                    . " JOIN {$terms} atr ON atr.attribute_id={$attributeId}"
                    . " AND atr.source_id=MOD(t.id+d.n,100)+1 WHERE d.n<{$count}");
            }
            foreach (['taxa' => 100000, 'occurrences' => 2000000, 'taxon_attribute_values' => 1000000] as $table => $count) {
                $this->assertSame($count, $db->table($table)->countAllResults());
            }
            foreach (['taxa', 'occurrences'] as $resource) {
                foreach (['sqs', 'habitat'] as $key) {
                    foreach ([0, 10000] as $offset) {
                        $measurements = [];
                        for ($iteration = 0; $iteration < 6; $iteration++) {
                            $started = hrtime(true);
                            $builder = $db->table('taxa t')->select('t.id AS taxon_id');
                            $tie = 't.id';
                            if ($resource === 'occurrences') {
                                $builder->join('occurrences o', 'o.taxon_id=t.id')->select('o.id');
                                $tie = 'o.id';
                            }
                            $expression = $service->sortExpression($key, 'ASC');
                            $rows = $builder->orderBy('(' . $expression . ' IS NULL)', 'ASC', false)
                                ->orderBy($expression, 'ASC', false)->orderBy($tie)
                                ->limit(50, $offset)->get()->getResultArray();
                            $hydrated = $service->read(array_map('intval', array_column($rows, 'taxon_id')));
                            $this->assertCount(50, $rows);
                            $this->assertSame(count(array_unique(array_column($rows, 'taxon_id'))), count($hydrated));
                            if ($iteration > 0) {
                                $measurements[] = (hrtime(true) - $started) / 1e9;
                            }
                        }
                        // Five warm measurements: nearest-rank p95 is their maximum.
                        fwrite(STDERR, sprintf(
                            "\n%s sort=%s offset=%d query+hydration p95=%.3fs\n",
                            $resource, $key, $offset, max($measurements),
                        ));
                    }
                }
            }
        } finally {
            if ($migration !== null) {
                $migration->down();
            }
            foreach (array_reverse($created) as $table) {
                $forge->dropTable($table, true);
            }
            $db->close();
        }
    }

    /**
     * Open only an explicitly named validation database with deployment-like connection settings.
     *
     * @return BaseConnection Private connection with a random fixture prefix.
     */
    private function validationConnection(): BaseConnection
    {
        $database = getenv('TANHUB_ATTRIBUTE_MYSQL_DATABASE') ?: '';
        if ($database === '') {
            $this->markTestSkipped('Set TANHUB_ATTRIBUTE_MYSQL_DATABASE to an isolated *_attributes_validation database.');
        }
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_]+_attributes_validation$/D', $database);
        $settings = config(Database::class)->default;
        $settings = array_replace($settings, [
            'DSN' => '', 'DBDriver' => 'MySQLi', 'database' => $database,
            'DBPrefix' => 'attr_' . bin2hex(random_bytes(5)) . '_',
            'hostname' => getenv('TANHUB_ATTRIBUTE_MYSQL_HOST') ?: $settings['hostname'],
            'username' => getenv('TANHUB_ATTRIBUTE_MYSQL_USER') ?: $settings['username'],
            'password' => getenv('TANHUB_ATTRIBUTE_MYSQL_PASSWORD') ?: $settings['password'],
            'port' => (int) (getenv('TANHUB_ATTRIBUTE_MYSQL_PORT') ?: $settings['port']),
            'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_general_ci',
            'pConnect' => false, 'DBDebug' => true,
        ]);
        return Database::connect($settings, false);
    }
}
