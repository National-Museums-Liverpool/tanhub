<?php

namespace Tests;

use App\Services\Export\ExportService;
use App\Services\Export\ExportTemplate;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use Config\Exports;
use InvalidArgumentException;
use RuntimeException;

/**
 * Exercise independent-request export capture, ordering, recovery and private artifacts.
 *
 * @internal
 */
final class ExportsTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    /** @var Exports Per-test processing and storage limits. */
    private Exports $limits;

    /** @var ExportService Isolated export lifecycle service. */
    private ExportService $exports;

    /** @var string Private random test-artifact directory. */
    private string $directory;

    /**
     * Create isolated record fixtures and private artifact storage.
     */
    protected function setUp(): void
    {
        parent::setUp();
        \Config\Services::reset();
        $_SESSION = [];
        $_COOKIE = [];
        $this->withSession([]);
        config(\Config\Auth::class)->actions['register'] = null;
        service('migrations')->setNamespace(null)->latest();
        $db = db_connect();
        $db->query('PRAGMA foreign_keys = OFF');
        foreach (['export_rows', 'export_jobs', 'geographic_regions_occurrences', 'occurrences',
            'taxon_stats', 'taxon_names', 'taxa', 'taxon_ranks', 'taxon_groups'] as $table) {
            $db->table($table)->emptyTable();
        }
        $db->table('data_sources')->upsert(['id' => 91, 'abbr' => 'EXPORT', 'title' => 'Export source',
            'url' => 'https://example.com']);
        $db->table('taxon_groups')->insert(['id' => 1, 'title' => 'Moths', 'friendly' => 'Moths',
            'external_key' => 'export', 'indicia_taxon_group_id' => 1]);
        $db->table('taxon_ranks')->insert(['id' => 40, 'rank' => 'Species', 'abbr' => 'sp',
            'sort_order' => 40, 'is_reporting' => 1]);
        config(\Config\TaxonSearch::class)->classificationRanks = [];
        foreach ([1 => 'Zulu', 2 => 'Alpha', 3 => 'Alpha', 4 => 'Beta'] as $id => $name) {
            $db->table('taxa')->insert(['id' => $id, 'scientific_name' => $name, 'vernacular_name' => '',
                'scientific_name_identifier' => 'SCI-' . $id, 'taxon_identifier' => 'TAX-' . $id,
                'taxon_rank_id' => 40, 'taxon_group_id' => 1, 'rarity_group_name' => 'Moths',
                'species_id' => $id]);
            $db->table('taxon_names')->insert(['id' => $id,
                'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
                'taxon_id' => $id, 'name' => $name, 'given_name_identifier' => 'NAME-' . $id]);
            $db->table('occurrences')->insert(['id' => $id, 'unique_key' => 'EXPORT:' . $id,
                'taxon_id' => $id, 'taxon_name_id' => $id, 'from_date' => $id === 1 ? null : '2010-01-01',
                'to_date' => $id === 3 ? '2010-12-31' : '2010-01-01', 'grid_ref' => 'SJ1234',
                'recorded_by' => "=HYPERLINK(\"bad\")\nRecorder", 'locality' => 'A, "quoted" place',
                'data_source_id' => 91, 'identification_verification_status' => 'V']);
        }
        $db->query('PRAGMA foreign_keys = ON');
        $this->directory = WRITEPATH . 'exports-test-' . bin2hex(random_bytes(8));
        $this->limits = new Exports();
        $this->limits->fileBudgetBytes = 10485760;
        $this->limits->freeReserveBytes = 1;
        $this->limits->stagingBudgetBytes = 10485760;
        $this->limits->batchSize = 1;
        $this->limits->cleanupBatchSize = 1;
        $this->exports = new ExportService($db, $this->limits, $this->directory);
        \Config\Services::injectMock('exportService', $this->exports);
    }

    /**
     * Remove only explicitly scoped test files and restore presentation configuration.
     */
    protected function tearDown(): void
    {
        if (is_dir($this->directory)) {
            foreach (new \DirectoryIterator($this->directory) as $entry) {
                if ($entry->isFile()) {
                    unlink($entry->getPathname());
                }
            }
            rmdir($this->directory);
        }
        config(\Config\TaxonSearch::class)->classificationRanks = ['Family'];
        \Config\Services::reset();
        parent::tearDown();
    }

    /**
     * Complete processing through separately constructed request services.
     *
     * @param array<string, mixed> $job Initial checkpoint.
     * @return array<string, mixed> Completed checkpoint.
     */
    private function finish(array $job): array
    {
        for ($request = 0; $request < 30 && $job['status'] !== 'completed'; $request++) {
            $job = (new ExportService(db_connect(), $this->limits, $this->directory))->process($job['uuid'], 1);
        }
        $this->assertSame('completed', $job['status']);
        return $job;
    }

    /**
     * Read test output with native CSV parsing and remove its BOM.
     *
     * @param array<string, mixed> $job Completed job.
     * @return list<list<string|null>> Parsed records.
     */
    private function records(array $job): array
    {
        $file = fopen($this->directory . '/' . $job['uuid'] . '.csv', 'rb');
        $this->assertSame("\xEF\xBB\xBF", fread($file, 3));
        $rows = [];
        while (($row = fgetcsv($file, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($file);
        return $rows;
    }

    /**
     * ID capture freezes values; later inserts and name edits cannot reorder captured rows.
     */
    public function testRollingCaptureUsesIdsAndFrozenGridSorting(): void
    {
        $job = $this->exports->create(1, 'taxa', [], 'grid');
        $job = $this->exports->process($job['uuid'], 1);
        $this->assertSame(1, (int) $job['capture_id']);
        db_connect()->table('taxa')->where('id', 1)->update(['scientific_name' => 'AAA changed']);
        $copy = db_connect()->table('taxa')->where('id', 4)->get()->getRowArray();
        $copy['id'] = 5;
        $copy['taxon_identifier'] = 'NEW';
        db_connect()->table('taxa')->insert($copy);
        $job = $this->finish($job);
        $rows = $this->records($job);
        $this->assertSame(['Alpha', 'Alpha', 'Beta', 'Zulu'], array_column(array_slice($rows, 1), 0));
        $this->assertSame(4, (int) $job['captured_rows']);
    }

    /**
     * Null and compound date ordering match the occurrence grid in both directions.
     */
    public function testCompoundDatesAndNullOrdering(): void
    {
        foreach (['asc' => [1, 2, 4, 3], 'desc' => [3, 4, 2, 1]] as $direction => $ids) {
            $job = $this->finish($this->exports->create(1, 'occurrences',
                ['sort' => 'from_date', 'direction' => $direction], 'details'));
            $rows = $this->records($job);
            $idColumn = array_search('Occurrence ID', $rows[0], true);
            $this->assertSame(array_map('strval', $ids), array_column(array_slice($rows, 1), $idColumn));
        }
    }

    /**
     * CSV quoting and formula protection preserve complete projected text values.
     */
    public function testCsvRoundTripsAndNeutralisesFormulas(): void
    {
        $job = $this->finish($this->exports->create(1, 'occurrences', ['taxon_id' => '2'], 'details'));
        $rows = $this->records($job);
        $this->assertCount(2, $rows);
        $this->assertSame("'=HYPERLINK(\"bad\")\nRecorder", $rows[1][array_search('Recorder', $rows[0], true)]);
        $this->assertSame('A, "quoted" place', $rows[1][array_search('Locality', $rows[0], true)]);
    }

    /**
     * Uncommitted tails are truncated before a retry appends the next batch.
     */
    public function testInterruptedCsvTailAndLostResponseRecovery(): void
    {
        $job = $this->exports->create(1, 'taxa', [], 'grid');
        while ($job['phase'] === 'capturing') {
            $job = $this->exports->process($job['uuid'], 1);
        }
        $job = $this->exports->process($job['uuid'], 1);
        file_put_contents($this->directory . '/' . $job['uuid'] . '.part', 'incomplete garbage', FILE_APPEND);
        $job = $this->finish($job);
        $this->assertCount(5, $this->records($job));
        $again = $this->exports->process($job['uuid'], 1);
        $this->assertSame($job['file_offset'], $again['file_offset']);
    }

    /**
     * Admission and lookup protect user limits and owner isolation.
     */
    public function testOwnerIsolationAndAdmissionLimit(): void
    {
        $job = $this->exports->create(1, 'taxa', [], 'grid');
        try {
            $this->exports->owned($job['uuid'], 2);
            $this->fail('A different owner must not read this export.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Export not found.', $exception->getMessage());
        }
        $this->exports->create(1, 'taxa', [], 'grid');
        $this->expectException(InvalidArgumentException::class);
        $this->exports->create(1, 'taxa', [], 'grid');
    }

    /**
     * Failed quota batches do not advance capture counters or insert staged rows.
     */
    public function testStagingQuotaFailureIsRecoverable(): void
    {
        $this->limits->stagingBudgetBytes = 1;
        $job = $this->exports->create(1, 'taxa', [], 'grid');
        try {
            $this->exports->process($job['uuid'], 1);
            $this->fail('Staging budget must be enforced.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('budget', $exception->getMessage());
        }
        $paused = $this->exports->owned($job['uuid'], 1);
        $this->assertSame('paused', $paused['status']);
        $this->assertSame(0, (int) $paused['capture_id']);
        $this->limits->stagingBudgetBytes = 10485760;
        $this->finish($paused);
    }

    /**
     * Expiry is enforced without cleanup, and active delivery prevents deletion.
     */
    public function testExpiryAndDeliveryProtection(): void
    {
        $job = $this->finish($this->exports->create(1, 'taxa', [], 'grid'));
        $response = $this->exports->download($job['uuid'], 1);
        db_connect()->table('export_jobs')->where('id', $job['id'])->update(['expires_at' => '2000-01-01 00:00:00']);
        $this->exports->cleanup();
        $this->assertFileExists($this->directory . '/' . $job['uuid'] . '.csv');
        unset($response);
        db_connect()->table('export_jobs')->where('id', $job['id'])->update(['delivery_until' => '2000-01-01 00:00:00']);
        $this->exports->cleanup();
        $this->assertFileDoesNotExist($this->directory . '/' . $job['uuid'] . '.csv');
        $this->expectException(InvalidArgumentException::class);
        $this->exports->download($job['uuid'], 1);
    }

    /**
     * Typed sort encoding preserves negative integers and nulls.
     */
    public function testNumericKeysAndFormulaTyping(): void
    {
        $values = [-100, -1, 0, 1, 100];
        $keys = array_map(static fn (int $value): string => ExportTemplate::sortKey($value, true)[1], $values);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($keys, $sorted);
        $this->assertSame([0, ''], ExportTemplate::sortKey(null, false));
        $this->assertSame(['-2.5', "' \t=evil"], ExportTemplate::cells(
            ['latitude' => -2.5, 'locality' => " \t=evil"], ['latitude' => 'Latitude', 'locality' => 'Locality']
        ));
    }

    /**
     * Staff routes validate applied filters and protect other owners' job information.
     */
    public function testStaffConfirmationAndOwnerProtectedRoutes(): void
    {
        $this->get('exports')->assertRedirect();
        $user = $this->staffUser();
        $confirmation = $this->get('exports/new?resource=occurrences&year=2010&sort=scientific_name');
        $confirmation->assertStatus(200);
        $confirmation->assertSee('Detailed occurrences');
        $this->get('exports/new?resource=taxa&year=2015-2010')->assertStatus(400);
        $job = $this->exports->create((int) $user->id, 'taxa', [], 'grid');
        $this->get('exports/' . $job['uuid'])->assertStatus(200);
        $other = $this->exports->create((int) $user->id + 1000, 'taxa', [], 'grid');
        foreach (['', '/status', '/download'] as $suffix) {
            try {
                $this->get('exports/' . $other['uuid'] . $suffix);
                $this->fail('Cross-owner routes must report not found.');
            } catch (\CodeIgniter\Exceptions\PageNotFoundException $exception) {
                $this->assertSame(404, $exception->getCode());
            }
        }
        $result = $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])->post(
            'exports/' . $job['uuid'] . '/process', [csrf_token() => csrf_hash()]
        );
        $result->assertStatus(200);
        $data = json_decode($result->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, (int) $data['data']['captured_rows']);
        $this->assertArrayHasKey('csrf', $data);
        $this->assertArrayNotHasKey('contract', $data['data']);
    }

    /**
     * Create and authenticate a unique manager for protected export pages.
     *
     * @return \CodeIgniter\Shield\Entities\User Active authenticated manager.
     */
    private function staffUser(): \CodeIgniter\Shield\Entities\User
    {
        $users = model(setting('Auth.userProvider'));
        $suffix = bin2hex(random_bytes(4));
        $user = $users->createNewUser(['username' => 'export-' . $suffix,
            'email' => $suffix . '@example.com', 'password' => 'Password123!']);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->activate();
        $users->save($user);
        $user->addGroup('manager');
        $this->actingAs($user);
        $this->withSession($_SESSION);
        return $user;
    }

    /**
     * Applied criteria resolve every selector and ordering to human-readable captions.
     */
    public function testConfirmationUsesSearchCaptionsAndSelectedLabels(): void
    {
        $this->staffUser();
        $db = db_connect();
        $db->table('taxon_groups')->where('id', 1)->update(['title' => 'Technical moth group', 'friendly' => 'Friendly moths']);
        $db->table('taxa')->where('id', 2)->update(['vernacular_name' => 'Common alpha']);
        $db->table('taxon_ranks')->insert(['id' => 2, 'rank' => 'Family', 'abbr' => 'fam',
            'sort_order' => 20, 'is_reporting' => 1]);
        $db->table('taxa')->insert(['id' => 10, 'scientific_name' => 'Example family', 'vernacular_name' => '',
            'scientific_name_identifier' => 'FAM-SCI', 'taxon_identifier' => 'FAM-TAX',
            'taxon_rank_id' => 2, 'taxon_group_id' => 1, 'rarity_group_name' => 'Moths', 'family_id' => 10]);
        $db->table('geographic_regions')->upsert(['id' => 91, 'higher_geography' => 'Westshire',
            'higher_geography_identifier' => 'EXPORT-REGION', 'location_type' => 'Vice county', 'data_source_id' => 91]);
        config(\Config\TaxonSearch::class)->classificationRanks = ['Family'];
        $criteria = ['resource' => 'taxa', 'q' => '<script>alert("summary")</script>',
            'year' => '2010', 'geographic_region_id' => '91', 'taxon_group_id' => '1',
            'family_id' => '10', 'taxon_id' => '2', 'taxon_name' => 'Alpha', 'rank_id' => '40',
            'data_source_id' => '91', 'blocked' => '1', 'taxon_blocked' => '0',
            'from_date' => '2010-01-01', 'to_date' => '2010-12-31', 'sort' => 'family_name', 'direction' => 'desc'];
        $result = $this->get('exports/new?' . http_build_query($criteria));
        $result->assertStatus(200);
        $document = new \DOMDocument();
        $document->loadHTML($result->getBody(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $summary = [];
        foreach ((new \DOMXPath($document))->query('//dl/dt') as $label) {
            $summary[$label->textContent] = trim($label->nextSibling->textContent);
        }
        $this->assertSame([
            'Search any descriptive field' => $criteria['q'], 'Year' => '2010',
            'Geographic region' => 'Westshire', 'Taxon group' => 'Friendly moths',
            'Selected taxon' => 'Alpha - Common alpha', 'Taxon (including descendants)' => 'Alpha',
            'Data source (matching records)' => 'Export source', 'Occurrence blocked' => 'Yes',
            'Taxon blocked' => 'No', 'From date' => '2010-01-01', 'To date' => '2010-12-31',
            'Rank' => 'Species', 'Family' => 'Example family', 'Sort by' => 'Family', 'Direction' => 'Descending',
        ], $summary);
        $this->assertStringNotContainsString($criteria['q'], $result->getBody());
    }

    /**
     * New exports auto-start once while revisited and paused exports remain under user control.
     */
    public function testNewExportStartsOnceAndShowsTemplateCaption(): void
    {
        $user = $this->staffUser();
        $created = $this->post('exports', [csrf_token() => csrf_hash(), 'resource' => 'taxa',
            'template' => 'grid', 'criteria' => '{}']);
        $created->assertRedirect();
        $uuid = basename(parse_url($created->response()->getHeaderLine('Location'), PHP_URL_PATH));
        $this->withSession($_SESSION);
        $page = $this->get('exports/' . $uuid);
        $page->assertStatus(200);
        $this->assertStringContainsString('data-auto-start="true"', $page->getBody());
        $page->assertSee('Taxon grid');
        $page->assertDontSee('grid/1');
        $this->withSession($_SESSION);
        $this->assertStringContainsString('data-auto-start="false"', $this->get('exports/' . $uuid)->getBody());
        $this->exports->action($uuid, (int) $user->id, 'pause');
        $this->assertStringContainsString('data-auto-start="false"', $this->get('exports/' . $uuid)->getBody());
    }

    /**
     * Missing stored stats stay explicitly unavailable rather than fabricating counts.
     */
    public function testMissingStatisticsAndEmptyExport(): void
    {
        $job = $this->finish($this->exports->create(1, 'taxa', [], 'stats'));
        $rows = $this->records($job);
        $this->assertSame('No', $rows[1][array_search('Stats available', $rows[0], true)]);
        $this->assertNull($rows[1][array_search('Occurrences count', $rows[0], true)] ?: null);
        $this->assertNotContains('Stored all-date statistics scope', $rows[0]);
        $this->assertNotContains('stats_scope', array_keys(json_decode($job['contract'], true)['columns']));
        $empty = $this->finish($this->exports->create(1, 'taxa', ['q' => 'no-match-here'], 'grid'));
        $this->assertCount(1, $this->records($empty));
    }

    /**
     * Interrupted publication is reconciled against the committed complete file offset.
     */
    public function testRenameBeforeCompletionAndRegeneration(): void
    {
        $job = $this->exports->create(1, 'taxa', [], 'grid');
        while ($job['phase'] === 'capturing' || (int) $job['written_rows'] < (int) $job['captured_rows']) {
            $job = $this->exports->process($job['uuid'], 1);
        }
        rename($this->directory . '/' . $job['uuid'] . '.part', $this->directory . '/' . $job['uuid'] . '.csv');
        $job = $this->exports->process($job['uuid'], 1);
        $this->assertSame('completed', $job['status']);
        $this->assertCount(5, $this->records($job));

        $unfinished = $this->exports->create(1, 'taxa', [], 'grid');
        while ($unfinished['phase'] === 'capturing') {
            $unfinished = $this->exports->process($unfinished['uuid'], 1);
        }
        $unfinished = $this->exports->process($unfinished['uuid'], 1);
        $this->exports->action($unfinished['uuid'], 1, 'regenerate');
        $this->assertCount(5, $this->records($this->finish($unfinished)));
    }

    /**
     * SQL filtering jumps directly to matching IDs and never revisits passed IDs.
     */
    public function testFilteredWindowsAndChangedUncapturedRows(): void
    {
        $job = $this->exports->create(1, 'taxa', ['q' => 'Beta'], 'grid');
        $job = $this->exports->process($job['uuid'], 1);
        $this->assertSame(4, (int) $job['capture_id']);
        $this->assertSame(1, (int) $job['captured_rows']);
        $this->assertSame('capturing', $job['phase']);
        db_connect()->table('taxa')->where('id', 1)->update(['scientific_name' => 'Beta after cursor']);
        $rows = $this->records($this->finish($job));
        $this->assertCount(2, $rows);
        $this->assertSame('Beta', $rows[1][0]);
    }

    /**
     * Capture batches contain matching rows rather than unfiltered source windows.
     */
    public function testFilteredBatchesFillWithMatchingRows(): void
    {
        $this->limits->batchSize = 2;
        $job = $this->exports->create(1, 'taxa', ['q' => 'Alpha'], 'grid');
        $job = $this->exports->process($job['uuid'], 1);
        $this->assertSame(2, (int) $job['captured_rows']);
        $this->assertSame(3, (int) $job['capture_id']);
        $this->assertSame(2, (int) $this->finish($job)['written_rows']);
    }

    /**
     * A recent-date export starts at qualifying records regardless of their high IDs.
     */
    public function testDateFilteringSkipsUnrelatedOccurrenceIds(): void
    {
        $copy = db_connect()->table('occurrences')->where('id', 2)->get()->getRowArray();
        $copy['id'] = 5000001;
        $copy['unique_key'] = 'EXPORT:TODAY';
        $copy['from_date'] = $copy['to_date'] = '2026-10-13';
        db_connect()->table('occurrences')->insert($copy);
        $job = $this->exports->create(1, 'occurrences',
            ['from_date' => '2026-10-13', 'to_date' => '2026-10-13'], 'details');
        $job = $this->exports->process($job['uuid'], 1);
        $this->assertSame(5000001, (int) $job['capture_id']);
        $this->assertSame(1, (int) $job['captured_rows']);
        $rows = $this->records($this->finish($job));
        $this->assertCount(2, $rows);
        $this->assertSame('5000001', $rows[1][array_search('Occurrence ID', $rows[0], true)]);
    }

    /**
     * Grid estimates survive confirmation, creation, status and revisited progress pages.
     */
    public function testGridCountReachesExportProgress(): void
    {
        $user = $this->staffUser();
        foreach (['taxa', 'occurrences'] as $resource) {
            $grid = $this->get($resource . '?q=Alpha');
            $grid->assertStatus(200);
            $document = new \DOMDocument();
            $document->loadHTML($grid->getBody(), LIBXML_NOERROR | LIBXML_NOWARNING);
            $links = (new \DOMXPath($document))->query('//a[contains(@href, "exports/new")]');
            $this->assertSame(1, $links->length);
            parse_str(parse_url($links->item(0)->getAttribute('href'), PHP_URL_QUERY), $parameters);
            $this->assertSame('2', $parameters['estimated_total']);
            $confirmation = $this->get('exports/new?' . http_build_query($parameters));
            $confirmation->assertStatus(200);
            $this->assertStringContainsString('name="estimated_total" value="2"', $confirmation->getBody());
        }
        $created = $this->post('exports', [csrf_token() => csrf_hash(), 'resource' => 'taxa',
            'template' => 'grid', 'criteria' => '{"q":"Alpha"}', 'estimated_total' => '2']);
        $created->assertRedirect();
        $uuid = basename(parse_url($created->response()->getHeaderLine('Location'), PHP_URL_PATH));
        $job = $this->exports->process($uuid, (int) $user->id);
        $this->withSession($_SESSION);
        $page = $this->get('exports/' . $uuid);
        $page->assertSee('out of approximately 2 rows');
        $this->assertStringContainsString('aria-valuenow="50"', $page->getBody());
        $this->assertStringContainsString('width: 50%', $page->getBody());
        $status = json_decode($this->get('exports/' . $uuid . '/status')->getJSON(), true);
        $this->assertSame(2, $status['data']['estimated_total']);
        $this->assertArrayNotHasKey('contract', $status['data']);
        $this->assertSame(1, (int) $job['captured_rows']);
    }

    /**
     * Invalid estimates report errors instead of being silently trusted or discarded.
     */
    public function testInvalidGridCountsAreRejected(): void
    {
        $this->staffUser();
        foreach (['-1', '1.5', 'abc', '999999999999999999999999', ['4']] as $estimate) {
            $this->get('exports/new?' . http_build_query(['resource' => 'taxa', 'estimated_total' => $estimate]))
                ->assertStatus(400);
            $this->post('exports', [csrf_token() => csrf_hash(), 'resource' => 'taxa',
                'template' => 'grid', 'criteria' => '{}', 'estimated_total' => $estimate])->assertRedirect();
            $this->assertSame(0, db_connect()->table('export_jobs')->countAllResults());
            $this->assertStringContainsString('non-negative', (string) session('error'));
            $this->withSession($_SESSION);
        }
    }

    /**
     * Stale and zero estimates cannot truncate actual captured output.
     */
    public function testGridEstimateNeverLimitsMembership(): void
    {
        foreach ([0, 1, 100, null] as $estimate) {
            $job = $this->finish($this->exports->create(1, 'taxa', [], 'grid', $estimate));
            $this->assertSame(4, (int) $job['written_rows']);
            $this->assertSame($estimate, json_decode($job['contract'], true)['estimated_total']);
        }
    }

    /**
     * Simplified statistics keep their values and expose scope once in job metadata.
     */
    public function testStatisticsHeadingsValuesAndScopeMetadata(): void
    {
        db_connect()->table('taxon_stats')->insert(['uuid' => '00000000-0000-4000-8000-000000000002',
            'taxon_id' => 2, 'geographic_region_id' => null,
            'occurrences_count' => 23, 'grid_square_count' => 7, 'first_record_date' => '2010-01-01',
            'last_record_date' => '2010-12-31', 'first_recorder' => 'First recorder', 'last_recorder' => 'Last recorder',
            'first_verified_recorder' => '', 'last_verified_recorder' => '']);
        $job = $this->finish($this->exports->create(1, 'taxa', ['q' => 'Alpha'], 'stats'));
        $rows = $this->records($job);
        $columns = ExportTemplate::columns(true, 'stats', []);
        $this->assertSame(array_values($columns), $rows[0]);
        foreach (['Stats available', 'Occurrences count', 'Grid square count', 'Frequency trend',
            'First verified record date', 'Last verified recorder'] as $heading) {
            $this->assertContains($heading, $rows[0]);
        }
        $this->assertSame('23', $rows[1][array_search('Occurrences count', $rows[0], true)]);
        $this->assertSame('Yes', $rows[1][array_search('Stats available', $rows[0], true)]);
        $this->assertSame('Global', json_decode($job['contract'], true)['statistics_scope']);

        db_connect()->table('geographic_regions')->upsert(['id' => 91, 'higher_geography' => 'Westshire',
            'higher_geography_identifier' => 'EXPORT-REGION', 'location_type' => 'Vice county', 'data_source_id' => 91]);
        db_connect()->table('geographic_regions_occurrences')->insert(['geographic_region_id' => 91, 'occurrence_id' => 2]);
        db_connect()->table('taxon_stats')->insert(['uuid' => '00000000-0000-4000-8000-000000000003',
            'taxon_id' => 2, 'geographic_region_id' => 91, 'occurrences_count' => 7, 'grid_square_count' => 3,
            'first_recorder' => '', 'last_recorder' => '', 'first_verified_recorder' => '', 'last_verified_recorder' => '']);
        $regional = $this->finish($this->exports->create(1, 'taxa', ['geographic_region_id' => '91'], 'stats'));
        $regionalRows = $this->records($regional);
        $this->assertSame('Westshire', json_decode($regional['contract'], true)['statistics_scope']);
        $this->assertSame('7', $regionalRows[1][array_search('Occurrences count', $regionalRows[0], true)]);

        $user = $this->staffUser();
        $owned = $this->exports->create((int) $user->id, 'taxa', ['geographic_region_id' => '91'], 'stats');
        $this->get('exports/' . $owned['uuid'])->assertSee('Statistics scope');
        $this->get('exports/' . $owned['uuid'])->assertSee('Westshire');
    }

    /**
     * Existing version one jobs retain frozen columns and remain resumable.
     */
    public function testLegacyTemplateStillCompletes(): void
    {
        $job = $this->exports->create(1, 'taxa', [], 'stats');
        $contract = json_decode($job['contract'], true);
        $contract['version'] = 1;
        unset($contract['estimated_total'], $contract['statistics_scope']);
        $contract['columns']['stats_scope'] = 'Stored all-date statistics scope';
        $contract['columns']['stats_available'] = 'Stored all-date statistics available';
        $contract['columns']['occurrences_count'] = 'Stored all-date occurrences count';
        db_connect()->table('export_jobs')->where('id', $job['id'])->update(['contract' => json_encode($contract)]);
        $rows = $this->records($this->finish($job));
        $this->assertSame('Global', $rows[1][array_search('Stored all-date statistics scope', $rows[0], true)]);
        $this->assertSame('No', $rows[1][array_search('Stored all-date statistics available', $rows[0], true)]);
    }

    /**
     * Live occurrence evidence stays filter-equivalent when taxa candidates are bounded.
     */
    public function testTaxonOccurrenceEvidenceAndCancelledCleanup(): void
    {
        $this->limits->batchSize = 1;
        $evidenceSql = [];
        $listener = static function (\CodeIgniter\Database\Query $query) use (&$evidenceSql): void {
            $sql = $query->getQuery();
            if (str_contains($sql, 'evidence') && str_contains($sql, '2010-01-01')) {
                $evidenceSql[] = $sql;
            }
        };
        Events::on('DBQuery', $listener);
        try {
            $first = $this->exports->create(1, 'taxa', ['year' => '2010'], 'grid');
            $first = $this->exports->process($first['uuid'], 1);
        } finally {
            Events::removeListener('DBQuery', $listener);
        }
        $this->assertSame(1, (int) $first['capture_id']);
        $this->assertSame(0, (int) $first['captured_rows']);
        $this->assertNotEmpty($evidenceSql);
        $this->assertMatchesRegularExpression(
            '/evidence.*(?:taxon_id|species_id).*IN\s*\(\s*1\s*\)/s',
            implode("\n", $evidenceSql)
        );
        $job = $this->finish($first);
        $this->assertSame(['Alpha', 'Alpha', 'Beta'], array_column(array_slice($this->records($job), 1), 0));
        $cancelled = $this->exports->create(1, 'taxa', [], 'grid');
        $this->exports->process($cancelled['uuid'], 1);
        $this->exports->action($cancelled['uuid'], 1, 'cancel');
        for ($request = 0; $request < 8; $request++) {
            $this->exports->cleanup();
        }
        $this->assertSame(0, db_connect()->table('export_rows')->where('job_id', $cancelled['id'])->countAllResults());
        $this->assertSame('cancelled', $this->exports->owned($cancelled['uuid'], 1)['status']);
    }

    /**
     * Competing processing requests never write through an already held global lock.
     */
    public function testBusyRequestAndInactiveJob(): void
    {
        $job = $this->exports->create(1, 'taxa', [], 'grid');
        $lock = fopen($this->directory . '/global.lock', 'c+b');
        flock($lock, LOCK_EX);
        try {
            $this->exports->process($job['uuid'], 1);
            $this->fail('Concurrent request must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('busy', $exception->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $this->assertSame(0, (int) $this->exports->owned($job['uuid'], 1)['capture_id']);
        db_connect()->table('export_jobs')->where('id', $job['id'])->update(['progress_at' => '2000-01-01 00:00:00']);
        $this->assertSame('expired', $this->exports->process($job['uuid'], 1)['status']);
    }

    /**
     * Sparse large files keep 64-bit content lengths and can use protected offloading.
     */
    public function testLargeFileHeadersAndOffload(): void
    {
        $job = $this->finish($this->exports->create(1, 'taxa', [], 'grid'));
        $file = fopen($this->directory . '/' . $job['uuid'] . '.csv', 'c+b');
        $bytes = 4294967296 + 17;
        $this->assertTrue(ftruncate($file, $bytes));
        fclose($file);
        clearstatcache();
        db_connect()->table('export_jobs')->where('id', $job['id'])->update(['file_offset' => $bytes]);
        $this->limits->xAccelRedirectPrefix = '/private-exports/';
        $response = $this->exports->download($job['uuid'], 1);
        $response->buildHeaders();
        $this->assertSame((string) $bytes, $response->getHeaderLine('Content-Length'));
        $this->assertSame('/private-exports/' . $job['uuid'] . '.csv', $response->getHeaderLine('X-Accel-Redirect'));
        ob_start();
        $response->sendBody();
        $this->assertSame('', ob_get_clean());
        unset($response);
    }
}
