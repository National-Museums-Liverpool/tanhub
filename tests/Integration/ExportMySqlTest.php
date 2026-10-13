<?php

namespace Tests;

use App\Services\Export\ExportService;
use App\Services\Search\TaxonSearchService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use Config\Exports;

/**
 * Opt-in, isolated MySQL-compatible collation, indexed batching and scale validation.
 *
 * @internal
 */
final class ExportMySqlTest extends CIUnitTestCase
{
    private ?BaseConnection $exportDb = null;
    private string $directory;
    private Exports $limits;
    private ExportService $exports;
    private array $tables = [];

    /**
     * Install only the necessary real migrations in a dedicated validation database.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $database = getenv('TANHUB_EXPORT_TEST_DATABASE') ?: '';
        if ($database === '') {
            $this->markTestSkipped('Set TANHUB_EXPORT_TEST_DATABASE to an isolated *_exports_validation database.');
        }
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_]+_exports_validation$/D', $database);
        $connection = config(Database::class)->default;
        $connection = array_replace($connection, [
            'database' => $database, 'hostname' => getenv('TANHUB_EXPORT_TEST_HOST') ?: '127.0.0.1',
            'username' => getenv('TANHUB_EXPORT_TEST_USER') ?: 'root',
            'password' => getenv('TANHUB_EXPORT_TEST_PASSWORD') ?: '',
            'port' => (int) (getenv('TANHUB_EXPORT_TEST_PORT') ?: 3306),
            'DBDriver' => 'MySQLi', 'DBPrefix' => 'e' . bin2hex(random_bytes(1)) . '_',
            'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_general_ci', 'pConnect' => false,
        ]);
        $this->exportDb = Database::connect($connection, false);
        Events::removeAllListeners('DBQuery');
        $forge = Database::forge($this->exportDb);
        foreach ([
            '2026-06-15-090000_CreateTaxonRanksTable',
            '2026-06-15-110250_CreateTaxonGroupsTable',
            '2026-06-15-110300_CreateRecordingSchemesTable',
            '2026-06-15-120000_CreateTaxaTable',
            '2026-06-18-090000_CreateDataSourcesTable',
            '2026-06-18-091000_CreateTaxonNamesTable',
            '2026-06-18-092000_CreateOccurrencesTable',
            '2026-07-06-100000_CreateGeographicRegionsTable',
            '2026-07-06-101000_CreateGeographicRegionsOccurrencesTable',
            '2026-07-07-101000_CreateTaxonStatsTable',
            '2026-09-29-120000_AllowNullTaxonStatsDates',
            '2026-10-13-090000_CreateExports',
        ] as $filename) {
            require_once APPPATH . 'Database/Migrations/' . $filename . '.php';
            $class = 'App\\Database\\Migrations\\' . substr($filename, 18);
            (new $class($forge))->up();
            $this->tables = $this->exportDb->listTables();
        }
        $this->tables = $this->exportDb->listTables();
        config(\Config\TaxonSearch::class)->classificationRanks = [];
        $this->exportDb->table('taxon_ranks')->insert(['id' => 1, 'rank' => 'Species',
            'abbr' => 'sp', 'sort_order' => 1, 'is_reporting' => 1]);
        $this->exportDb->table('taxon_groups')->insert(['id' => 1, 'title' => 'Moths', 'friendly' => 'Moths',
            'external_key' => 'test', 'indicia_taxon_group_id' => 1]);
        $this->exportDb->table('data_sources')->insert(['id' => 1, 'abbr' => 'TEST', 'title' => 'Test',
            'url' => 'https://example.com']);
        foreach ([1 => 'zulu', 2 => 'Éclair', 3 => 'eclair', 4 => 'Alpha'] as $id => $name) {
            $this->exportDb->table('taxa')->insert(['id' => $id, 'scientific_name' => $name,
                'vernacular_name' => '', 'taxon_identifier' => 'TAX-' . $id,
                'scientific_name_identifier' => 'SCI-' . $id, 'taxon_group_id' => 1,
                'taxon_rank_id' => 1, 'rarity_group_name' => 'Moths']);
            $this->exportDb->table('taxon_names')->insert(['id' => $id,
                'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id), 'name' => $name,
                'taxon_id' => $id, 'given_name_identifier' => 'NAME-' . $id]);
        }
        $this->directory = WRITEPATH . 'export-mysql-test-' . bin2hex(random_bytes(8));
        $this->limits = new Exports();
        $this->limits->fileBudgetBytes = 10737418240;
        $this->limits->freeReserveBytes = 1048576;
        $this->limits->stagingBudgetBytes = 4294967296;
        $this->exports = new ExportService($this->exportDb, $this->limits, $this->directory);
    }

    /**
     * Remove only random-prefix test tables and explicitly scoped test artifacts.
     */
    protected function tearDown(): void
    {
        if ($this->exportDb !== null) {
            $this->exportDb->query('SET FOREIGN_KEY_CHECKS=0');
            foreach ($this->tables as $table) {
                if (str_starts_with($table, $this->exportDb->DBPrefix)) {
                    $this->exportDb->query('DROP TABLE ' . $this->exportDb->protectIdentifiers($table));
                }
            }
            $this->exportDb->close();
        }
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (new \DirectoryIterator($this->directory) as $entry) {
                if ($entry->isFile()) {
                    unlink($entry->getPathname());
                }
            }
            rmdir($this->directory);
        }
        config(\Config\TaxonSearch::class)->classificationRanks = ['Family'];
        parent::tearDown();
    }

    /**
     * MySQL case/accent ties retain ID order and indexed staging preserves the grid.
     */
    public function testCollationAndEveryTaxonSort(): void
    {
        $this->limits->batchSize = 1;
        foreach (['id', 'scientific_name', 'vernacular_name', 'taxon_identifier', 'rank_title',
            'taxon_group_title', 'conservation_status', 'blocked'] as $sort) {
            foreach (['asc', 'desc'] as $direction) {
                $search = new TaxonSearchService($this->exportDb);
                $input = ['sort' => $sort, 'direction' => $direction];
                $query = $search->query($search->criteria([], true), true);
                $search->orderQuery($query, $search->ordering($input, true), true);
                $expected = array_column($query->get()->getResultArray(), 'scientific_name');
                $job = $this->exports->create(1, 'taxa', $input, 'grid');
                while ($job['status'] !== 'completed') {
                    $job = $this->exports->process($job['uuid'], 1);
                }
                $file = fopen($this->directory . '/' . $job['uuid'] . '.csv', 'rb');
                fread($file, 3);
                fgetcsv($file, null, ',', '"', '');
                $actual = [];
                while (($row = fgetcsv($file, null, ',', '"', '')) !== false) {
                    $actual[] = $row[0];
                }
                fclose($file);
                $this->assertSame($expected, $actual, $sort . ' ' . $direction);
            }
        }

    }

    /**
     * Every occurrence ordering and taxon evidence filter matches the live grid query.
     */
    public function testOccurrenceSortsAndBoundedEvidence(): void
    {
        $this->limits->batchSize = 2;
        for ($id = 1; $id <= 12; $id++) {
            $taxon = $id % 4 + 1;
            $this->exportDb->table('occurrences')->insert(['id' => $id, 'unique_key' => 'TEST:' . $id,
                'taxon_id' => $taxon, 'taxon_name_id' => $taxon,
                'from_date' => $id % 3 === 0 ? null : '2010-01-01',
                'to_date' => $id % 2 === 0 ? '2010-12-31' : '2010-01-01',
                'grid_ref' => $id % 2 === 0 ? 'SJ1234' : 'SU1234', 'data_source_id' => 1,
                'recorded_by' => 'Recorder', 'identification_verification_status' => 'V']);
        }
        $search = new TaxonSearchService($this->exportDb);
        foreach (['id', 'unique_key', 'from_date', 'to_date', 'grid_ref', 'data_source_id', 'blocked',
            'taxon_id', 'taxon_name_id', 'scientific_name', 'vernacular_name', 'taxon_group_title',
            'data_source_title'] as $sort) {
            foreach (['asc', 'desc'] as $direction) {
                $input = ['sort' => $sort, 'direction' => $direction];
                $query = $search->query($search->criteria([], false), false);
                $search->orderQuery($query, $search->ordering($input, false), false);
                $expected = array_map('strval', array_column($query->get()->getResultArray(), 'id'));
                $job = $this->exports->create(1, 'occurrences', $input, 'details');
                while ($job['status'] !== 'completed') {
                    $job = $this->exports->process($job['uuid'], 1);
                }
                $file = fopen($this->directory . '/' . $job['uuid'] . '.csv', 'rb');
                fread($file, 3);
                $headers = fgetcsv($file, null, ',', '"', '');
                $idColumn = array_search('Occurrence ID', $headers, true);
                $actual = [];
                while (($row = fgetcsv($file, null, ',', '"', '')) !== false) {
                    $actual[] = $row[$idColumn];
                }
                fclose($file);
                $this->assertSame($expected, $actual, $sort . ' ' . $direction);
            }
        }
        $expected = array_column($search->query($search->criteria(['year' => '2010'], true), true)
            ->get()->getResultArray(), 'id');
        $job = $this->exports->create(1, 'taxa', ['year' => '2010'], 'grid');
        while ($job['phase'] === 'capturing') {
            $job = $this->exports->process($job['uuid'], 1);
        }
        $actual = array_column($this->exportDb->table('export_rows')->where('job_id', $job['id'])
            ->orderBy('record_id')->get()->getResultArray(), 'record_id');
        sort($expected);
        $this->assertSame($expected, $actual);
    }

    /**
     * Two million rows remain bounded per request and match an independent ordered cursor.
     */
    public function testMultiMillionRowRequests(): void
    {
        $total = (int) (getenv('TANHUB_EXPORT_SCALE_ROWS') ?: 0);
        if ($total < 2000000) {
            $this->markTestSkipped('Set TANHUB_EXPORT_SCALE_ROWS=2000000 for the scale test.');
        }
        for ($first = 1; $first <= $total; $first += 2000) {
            $batch = [];
            for ($id = $first; $id < min($first + 2000, $total + 1); $id++) {
                $taxon = $id % 4 + 1;
                $batch[] = ['id' => $id, 'unique_key' => 'SCALE:' . $id, 'taxon_id' => $taxon,
                    'taxon_name_id' => $taxon, 'from_date' => '2010-01-01', 'to_date' => '2010-01-01',
                    'grid_ref' => 'SJ1234', 'data_source_id' => 1, 'recorded_by' => 'Recorder',
                    'identification_verification_status' => 'V'];
            }
            $this->exportDb->table('occurrences')->insertBatch($batch);
        }
        unset($batch);
        $this->exportDb->table('occurrences')->where('id >', $total - 20)
            ->update(['from_date' => '2026-10-13', 'to_date' => '2026-10-13']);
        $filteredSql = null;
        $occurrenceTable = $this->exportDb->prefixTable('occurrences');
        Events::on('DBQuery', static function (\CodeIgniter\Database\Query $query) use (&$filteredSql, $occurrenceTable): void {
            $sql = $query->getQuery();
            if ((str_starts_with($sql, 'SELECT `o`.`id`') || str_starts_with($sql, 'SELECT STRAIGHT_JOIN o.id'))
                && str_contains($sql, '`' . $occurrenceTable . '`')
                && str_contains($sql, '2026-10-13')) {
                $filteredSql = $sql;
            }
        });
        $recent = $this->exports->create(1, 'occurrences',
            ['from_date' => '2026-10-13', 'to_date' => '2026-10-13'], 'grid', 20);
        $start = microtime(true);
        $recent = $this->exports->process($recent['uuid'], 1);
        $this->assertLessThan(5.0, microtime(true) - $start);
        $this->assertSame(20, (int) $recent['captured_rows']);
        $this->assertSame($total, (int) $recent['capture_id']);
        $this->assertNotNull($filteredSql);
        $plan = $this->exportDb->query('EXPLAIN ' . $filteredSql)->getResultArray();
        $occurrencePlan = array_values(array_filter($plan, static fn (array $row): bool => $row['table'] === 'o'))[0];
        $this->assertNotNull($occurrencePlan['key']);
        $this->assertLessThan(1000, (int) $occurrencePlan['rows'], 'The date query must not scan unrelated millions of records.');
        $recent = $this->exports->process($recent['uuid'], 1);
        $this->assertSame('writing', $recent['phase']);
        while ($recent['status'] !== 'completed') {
            $recent = $this->exports->process($recent['uuid'], 1);
        }
        $this->assertSame(20, (int) $recent['written_rows']);
        Events::removeAllListeners('DBQuery');
        $job = $this->exports->create(1, 'occurrences',
            ['sort' => 'scientific_name', 'direction' => 'asc'], 'details');
        $stageSql = null;
        $captureSql = null;
        $slowestSql = '';
        $slowestQuery = 0.0;
        $stageTable = $this->exportDb->prefixTable('export_rows');
        Events::on('DBQuery', static function (\CodeIgniter\Database\Query $query) use (
            &$stageSql, &$captureSql, &$slowestSql, &$slowestQuery, $stageTable
        ): void {
            $sql = $query->getQuery();
            if ($query->getDuration() > $slowestQuery) {
                $slowestQuery = $query->getDuration();
                $slowestSql = $sql;
            }
            if (str_contains($sql, 'FROM `' . $stageTable . '`') && str_contains($sql, 'ORDER BY')) {
                $stageSql = $sql;
            }
            if ($captureSql === null
                && (str_starts_with($sql, 'SELECT `o`.`id`') || str_starts_with($sql, 'SELECT STRAIGHT_JOIN o.id'))
                && str_contains($sql, 'LIMIT')) {
                $captureSql = $sql;
            }
        });
        $maximumRequest = 0.0;
        memory_reset_peak_usage();
        while ($job['status'] !== 'completed') {
            $slowestQuery = 0.0;
            $slowestSql = '';
            $start = microtime(true);
            $job = (new ExportService($this->exportDb, $this->limits, $this->directory))->process($job['uuid'], 1);
            $duration = microtime(true) - $start;
            $maximumRequest = max($maximumRequest, $duration);
            $this->assertLessThan(5.0, $duration, sprintf(
                'Each processing request must fit five seconds (phase %s, cursor %s). Slowest query %.3fs: %s',
                $job['phase'], $job['capture_id'], $slowestQuery, $slowestSql
            ));
            $this->assertLessThanOrEqual(134217728, memory_get_peak_usage(true));
        }
        $this->assertSame($total, (int) $job['written_rows']);
        $this->assertNotNull($captureSql);
        $capturePlan = $this->exportDb->query('EXPLAIN ' . $captureSql)->getResultArray();
        foreach ($capturePlan as $row) {
            $this->assertStringNotContainsString('Using filesort', $row['Extra']);
            $this->assertStringNotContainsString('Using temporary', $row['Extra']);
        }
        $this->assertNotNull($stageSql);
        $plan = $this->exportDb->query('EXPLAIN ' . $stageSql)->getRowArray();
        $this->assertNotNull($plan['key']);
        $this->assertStringNotContainsString('Using filesort', $plan['Extra']);
        Events::removeAllListeners('DBQuery');
        $file = fopen($this->directory . '/' . $job['uuid'] . '.csv', 'rb');
        fread($file, 3);
        $headers = fgetcsv($file, null, ',', '"', '');
        $idColumn = array_search('Occurrence ID', $headers, true);
        $seen = 0;
        // These are the database's independent collation groups, including the accent/case tie.
        foreach ([[0], [2, 3], [1]] as $taxa) {
            $cursor = 0;
            do {
                $expected = $this->exportDb->table('occurrences o')->select('o.id, t.scientific_name')
                    ->join('taxa t', 't.id = o.taxon_id')->where('o.id >', $cursor)
                    ->whereIn('o.taxon_id', array_map(static fn (int $id): int => $id === 0 ? 4 : $id, $taxa))
                    ->orderBy('o.id')->get(1000)->getResultArray();
                foreach ($expected as $row) {
                    $actual = fgetcsv($file, null, ',', '"', '');
                    $this->assertSame((string) $row['id'], $actual[$idColumn]);
                    $this->assertSame($row['scientific_name'], $actual[1]);
                    $cursor = $row['id'];
                    $seen++;
                }
            } while ($expected !== []);
        }
        $this->assertSame($total, $seen);
        $this->assertFalse(fgetcsv($file, null, ',', '"', ''));
        fclose($file);
        fwrite(STDERR, sprintf("\nExport scale: %d rows, %.3fs maximum batch, %.1f MiB peak PHP memory.\n",
            $total, $maximumRequest, memory_get_peak_usage(true) / 1048576));
    }
}
