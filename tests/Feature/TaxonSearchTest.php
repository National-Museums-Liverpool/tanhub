<?php

namespace Tests;

use App\Services\Search\TaxonSearchService;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\TaxonSearch;

/**
 * Cover staff search contracts, reporting evidence and query-plan regressions.
 *
 * @internal
 */
final class TaxonSearchTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    /**
     * Seed a complete hierarchy with records that distinguish filtering rules.
     *
     * Includes unrecorded taxa, a subspecies, a non-reporting aggregate mapped to
     * Species, vague/partial/deleted records and an occurrence linked to two regions.
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
        foreach (['taxon_media_variants', 'taxon_media', 'geographic_regions_occurrences',
            'occurrences', 'taxon_stats', 'taxon_year_stats', 'taxon_names', 'taxa',
            'taxon_ranks', 'taxon_groups', 'geographic_regions'] as $table) {
            $db->table($table)->emptyTable();
        }
        $db->table('data_sources')->upsert([
            'id' => 90, 'abbr' => 'SEARCH', 'title' => 'Search source', 'url' => 'https://example.com/search',
        ]);
        $db->table('taxon_groups')->insert([
            'id' => 1, 'title' => 'insect - moth', 'friendly' => 'Moths', 'external_key' => 'moth',
            'indicia_taxon_group_id' => 1,
        ]);
        foreach ([10 => 'Order', 20 => 'Family', 30 => 'Genus', 40 => 'Species',
            50 => 'Subspecies', 60 => 'Species aggregate'] as $id => $rank) {
            $db->table('taxon_ranks')->insert([
                'id' => $id, 'rank' => $rank, 'abbr' => 'r' . $id,
                'sort_order' => $id, 'is_reporting' => $id <= 40 ? 1 : 0,
            ]);
        }
        foreach ([
            [10, 'Lepidoptera', 10, null, null, null, null, 0],
            [20, 'Noctuidae', 20, 10, 20, null, null, 0],
            [21, 'Other family', 20, 10, 21, null, null, 0],
            [30, 'Agrotis', 30, 20, 20, 30, null, 0],
            [31, 'Unrecorded genus', 30, 20, 20, 31, null, 0],
            [40, 'Agrotis segetum', 40, 30, 20, 30, 40, 0],
            [41, 'Agrotis segetum child', 50, 40, 20, 30, 40, 0],
            [42, 'Blocked species', 40, 30, 20, 30, 42, 1],
            [50, 'Mapped aggregate', 60, 30, 20, 30, 50, 0],
            [51, 'Aggregate child', 50, 50, 20, 30, 50, 0],
        ] as [$id, $name, $rank, $parent, $family, $genus, $species, $blocked]) {
            $db->table('taxa')->insert([
                'id' => $id, 'scientific_name' => $name, 'vernacular_name' => $id === 40 ? 'Turnip moth' : '',
                'scientific_name_identifier' => 'SCI-' . $id, 'taxon_identifier' => 'TAX-' . $id,
                'taxon_rank_id' => $rank, 'parent_taxon_id' => $parent, 'taxon_group_id' => 1,
                'order_id' => 10, 'family_id' => $family, 'genus_id' => $genus, 'species_id' => $species,
                'rarity_group_name' => 'Moths', 'blocked' => $blocked,
            ]);
            $db->table('taxon_names')->insert([
                'id' => $id, 'uuid' => sprintf('00000000-0000-4000-8000-%012d', $id),
                'taxon_id' => $id, 'name' => $name, 'given_name_identifier' => 'NAME-' . $id,
                'accepted' => 1, 'scientific' => 1,
            ]);
        }
        $db->table('taxon_names')->insert([
            'id' => 100, 'uuid' => '00000000-0000-4000-8000-000000000100', 'taxon_id' => 40,
            'name' => 'Old turnip name', 'given_name_identifier' => 'OLD', 'accepted' => 0, 'scientific' => 1,
        ]);
        foreach ([1 => 'West region', 2 => 'East region'] as $id => $name) {
            $db->table('geographic_regions')->insert([
                'id' => $id, 'higher_geography' => $name, 'higher_geography_identifier' => 'REG-' . $id,
                'location_type' => 'Vice county', 'data_source_id' => 90,
            ]);
        }
        foreach ([
            [1, 41, '2010-01-01', '2010-12-31', 1, 0],
            [2, 40, '2012-05-01', '2012-05-01', 2, 0],
            [3, 42, '2010-05-01', '2010-05-01', 1, 1],
            [4, 40, '2010-01-01', '2012-12-31', 1, 0],
            [5, 40, '2010-01-01', null, 1, 0],
            [6, 40, null, null, 1, 0],
            [7, 40, '2011-01-01', '2011-01-01', 1, 0],
            [8, 30, '2015-01-01', '2015-01-01', 2, 0],
            [9, 51, '2016-01-01', '2016-01-01', 1, 0],
        ] as [$id, $taxon, $from, $to, $region, $blocked]) {
            $row = $db->table('taxa')->where('id', $taxon)->get()->getRowArray();
            $db->table('occurrences')->insert([
                'id' => $id, 'unique_key' => 'SEARCH:' . $id, 'taxon_id' => $taxon, 'taxon_name_id' => $taxon,
                'order_id' => $row['order_id'], 'family_id' => $row['family_id'],
                'genus_id' => $row['genus_id'], 'species_id' => $row['species_id'],
                'from_date' => $from, 'to_date' => $to, 'grid_ref' => 'SJ1234',
                'recorded_by' => 'Recorder Example', 'identified_by' => 'Identifier Example',
                'identification_verification_status' => 'V', 'locality' => 'Example locality',
                'data_source_id' => 90, 'blocked' => $blocked,
                'deleted_at' => $id === 7 ? '2020-01-01 00:00:00' : null,
            ]);
            $db->table('geographic_regions_occurrences')->insert(['occurrence_id' => $id, 'geographic_region_id' => $region]);
        }
        $db->table('geographic_regions_occurrences')->insert(['occurrence_id' => 1, 'geographic_region_id' => 2]);
        $db->query('PRAGMA foreign_keys = ON');
    }

    /**
     * Restore per-test configuration.
     */
    protected function tearDown(): void
    {
        config(TaxonSearch::class)->classificationRanks = ['Family'];
        parent::tearDown();
    }

    /**
     * Execute the unpaginated query and return stable numeric row identifiers.
     *
     * @param array<string, mixed> $input Filters.
     * @param bool $taxa Whether to query taxa.
     * @return list<int> Sorted row IDs.
     */
    private function ids(array $input, bool $taxa = true): array
    {
        $search = new TaxonSearchService();
        $criteria = $search->criteria($input, $taxa);
        $this->assertSame([], $criteria->errors);
        $ids = array_map('intval', array_column($search->query($criteria, $taxa)->get()->getResultArray(), 'id'));
        sort($ids);

        return $ids;
    }

    /**
     * Match descendant evidence at the independent requested rank.
     */
    public function testReportingAncestorsAndIndependentRankFilter(): void
    {
        $this->assertSame([30], $this->ids(['year' => '2010', 'family_id' => '20', 'rank_id' => '30']));
        $this->assertSame([40, 42], $this->ids(['year' => '2010', 'rank_id' => '40']));
        $this->assertSame([10], $this->ids(['year' => '2010', 'rank_id' => '10']));
        $this->assertSame([30], $this->ids(['year' => '2015', 'rank_id' => '30']));
        $this->assertSame([], $this->ids(['year' => '2016', 'rank_id' => '40']));
        $this->assertSame([10, 20, 21, 30, 31, 40, 41, 42, 50, 51], $this->ids([]));
    }

    /**
     * Containment excludes vague, partial and deleted intervals unless wholly included.
     */
    public function testDateContainmentAndOpenRanges(): void
    {
        $this->assertSame([1, 3], $this->ids(['year' => '2010'], false));
        $this->assertSame([], $this->ids(['year' => '2011'], false));
        $this->assertSame([1, 2, 3, 4], $this->ids(['year' => '2010-2012'], false));
        $this->assertSame([2], $this->ids(['year' => '2011-2012'], false));
        $this->assertSame([2, 8, 9], $this->ids(['year' => '2012-'], false));
        $this->assertSame([1, 2, 3, 4], $this->ids(['year' => '-2012'], false));
        $this->assertSame([3], $this->ids(['year' => '2010', 'from_date' => '2010-05-01', 'to_date' => '2010-05-01'], false));
    }

    /**
     * Region and year evidence must come from the same original occurrence.
     */
    public function testSameOccurrenceEvidenceAndDeduplication(): void
    {
        $this->assertSame([], $this->ids(['year' => '2012', 'geographic_region_id' => '1', 'rank_id' => '40']));
        $this->assertSame([40], $this->ids(['year' => '2012', 'geographic_region_id' => '2', 'rank_id' => '40']));
        $this->assertSame([40], $this->ids(['year' => '2010-2012', 'geographic_region_id' => '1',
            'rank_id' => '40', 'taxon_blocked' => '0']));
    }

    /**
     * Evidence is materialized once rather than correlated with every listed taxon.
     */
    public function testEvidenceQueryUsesExistingIndexes(): void
    {
        $search = new TaxonSearchService();
        $criteria = $search->criteria(['year' => '2010', 'geographic_region_id' => '1', 'rank_id' => '30'], true);
        $sql = $search->query($criteria, true)->getCompiledSelect();
        $plan = db_connect()->query('EXPLAIN QUERY PLAN ' . $sql)->getResultArray();
        $details = implode("\n", array_column($plan, 'detail'));

        $this->assertStringContainsString('MATERIALIZE matching_taxa', $details);
        $this->assertMatchesRegularExpression('/SEARCH evidence USING (?:.*INDEX|INTEGER PRIMARY KEY)/', $details);
        $this->assertMatchesRegularExpression('/SEARCH matched_region .*INDEX/', $details);
    }

    /**
     * Default inclusion and independent record/taxon moderation filters are preserved.
     */
    public function testBlockedAndDeletedData(): void
    {
        $this->assertContains(42, $this->ids([]));
        $this->assertSame([40], $this->ids(['year' => '2010', 'rank_id' => '40', 'taxon_blocked' => '0']));
        $this->assertSame([3], $this->ids(['blocked' => '1'], false));
        $this->assertSame([42], $this->ids(['blocked' => '1', 'rank_id' => '40']));
        $this->assertSame([], $this->ids(['blocked' => '1', 'taxon_blocked' => '0', 'rank_id' => '40']));
        db_connect()->table('taxa')->where('id', 30)->update(['blocked' => 1]);
        $this->assertSame([30], $this->ids(['year' => '2010', 'rank_id' => '30', 'taxon_blocked' => '1']));
        db_connect()->table('taxa')->where('id', 41)->update(['deleted_at' => '2020-01-01']);
        $this->assertSame([], $this->ids(['year' => '2010', 'rank_id' => '40', 'taxon_blocked' => '0']));
    }

    /**
     * Subtree selections support reporting and non-reporting taxa with cycle protection.
     */
    public function testSubtreeSelections(): void
    {
        $this->assertSame([40, 41], $this->ids(['taxon_id' => '40']));
        $this->assertSame([1, 2, 4, 5, 6], $this->ids(['taxon_id' => '40'], false));
        $this->assertSame([50, 51], $this->ids(['taxon_id' => '50']));
        $this->assertSame([9], $this->ids(['taxon_id' => '50'], false));
        db_connect()->table('taxa')->where('id', 50)->update(['parent_taxon_id' => 51]);
        $this->assertSame([50, 51], $this->ids(['taxon_id' => '50']));
    }

    /**
     * Search linked labels/synonyms without duplicated rows and hydrate all regions.
     */
    public function testTextSearchAndReadableRows(): void
    {
        $this->assertSame([40], $this->ids(['q' => 'Old turnip']));
        $this->assertSame([2, 4, 5, 6], $this->ids(['q' => 'Old turnip'], false));
        $this->assertSame([1, 2, 8], $this->ids(['q' => 'East region'], false));
        $this->assertSame([1, 2, 3, 4, 5, 6, 8, 9], $this->ids(['q' => 'Recorder'], false));
        $search = new TaxonSearchService();
        $page = $search->page(['year' => '2010', 'taxon_id' => '40'], false);
        $this->assertSame(1, $page['total']);
        $this->assertSame(['East region', 'West region'], $page['occurrences'][0]['regions']);
        $this->assertSame('2010-01-01 to 2010-12-31', $page['occurrences'][0]['date_label']);
        $this->assertSame('Moths', $page['occurrences'][0]['taxon_group_label']);
    }

    /**
     * Invalid references and malformed filters cannot widen a query.
     */
    public function testValidationAndClassificationConfiguration(): void
    {
        $search = new TaxonSearchService();
        foreach ([
            ['year' => '2012-2010'], ['taxon_id' => '999'], ['family_id' => '40'],
            ['rank_id' => '50'], ['geographic_region_id' => ['1']], ['blocked' => 'bad'],
        ] as $input) {
            $criteria = $search->criteria($input, true);
            $this->assertNotEmpty($criteria->errors);
            $this->assertSame([], $search->query($criteria, true)->get()->getResultArray());
        }
        config(TaxonSearch::class)->classificationRanks = ['Genus', 'Family'];
        $page = (new TaxonSearchService())->page([], true);
        $this->assertSame(['genus', 'family'], array_keys($page['classifications']));
        config(TaxonSearch::class)->classificationRanks = ['Invalid'];
        $this->expectException(\RuntimeException::class);
        new TaxonSearchService();
    }

    /**
     * Suggestions are bounded, synonym-aware and independent of checklist rank.
     */
    public function testLookupSemanticsAndLimit(): void
    {
        $search = new TaxonSearchService();
        $criteria = $search->criteria(['rank_id' => '40'], true);
        $matches = $search->lookup('Old turnip', $criteria);
        $this->assertSame([40], array_column($matches, 'id'));
        $this->assertSame('Old turnip name', $matches[0]['matched_name']);
        $this->assertContains(30, array_column($search->lookup('Agrotis', $criteria), 'id'));
        $this->assertSame([], $search->lookup('Blocked', $search->criteria(['taxon_blocked' => '0'], true)));
        for ($i = 100; $i < 125; $i++) {
            db_connect()->table('taxa')->insert([
                'id' => $i, 'taxon_identifier' => 'LIMIT-' . $i, 'scientific_name_identifier' => 'LIMIT-' . $i,
                'scientific_name' => 'Limit example ' . $i, 'vernacular_name' => '',
                'taxon_rank_id' => 40, 'taxon_group_id' => 1,
                'rarity_group_name' => 'Moths',
            ]);
        }
        $this->assertCount(20, $search->lookup('Limit', $criteria));
        $first = $search->page(['q' => 'Limit'], true);
        $second = $search->page(['q' => 'Limit', 'page' => '2'], true);
        $this->assertSame(25, $first['total']);
        $this->assertCount(20, $first['taxa']);
        $this->assertCount(5, $second['taxa']);
        $this->assertSame([], array_intersect(array_column($first['taxa'], 'id'), array_column($second['taxa'], 'id')));
    }

    /**
     * Configuring Class allows a blackbird occurrence to qualify Aves.
     */
    public function testBlackbirdQualifiesConfiguredAves(): void
    {
        $db = db_connect();
        $originalRanks = config('Import')->taxonRanks;
        // Add only this installation-specific rank, preserving existing SQLite foreign keys.
        foreach (['taxa', 'occurrences'] as $table) {
            $db->query('ALTER TABLE ' . $db->prefixTable($table) . ' ADD COLUMN class_id BIGINT NULL');
        }
        // fieldExists() caches schema metadata; the search service must see the added column.
        $db->resetDataCache();
        try {
            config('Import')->taxonRanks = ['Class', 'Order', 'Superfamily', 'Family', 'Genus', 'Species'];
            $db->table('taxon_ranks')->insert(['id' => 5, 'rank' => 'Class', 'abbr' => 'cl', 'is_reporting' => 1, 'sort_order' => 5]);
            foreach ([[5, 'Aves', 5, null], [62, 'Turdus merula', 40, 5]] as [$id, $name, $rank, $parent]) {
                $db->table('taxa')->insert([
                    'id' => $id, 'taxon_identifier' => 'BIRD-' . $id, 'scientific_name_identifier' => 'BIRD-' . $id,
                    'scientific_name' => $name, 'vernacular_name' => $id === 62 ? 'Blackbird' : 'Birds',
                    'taxon_rank_id' => $rank, 'taxon_group_id' => 1, 'rarity_group_name' => 'Birds',
                    'class_id' => 5, 'species_id' => $id === 62 ? 62 : null, 'parent_taxon_id' => $parent,
                ]);
            }
            $db->table('taxon_names')->insert([
                'id' => 62, 'uuid' => '00000000-0000-4000-8000-000000000062', 'taxon_id' => 62,
                'name' => 'Turdus merula', 'given_name_identifier' => 'BIRD-62',
            ]);
            $db->table('occurrences')->insert([
                'id' => 10, 'unique_key' => 'BIRD:10', 'taxon_id' => 62, 'taxon_name_id' => 62,
                'class_id' => 5, 'species_id' => 62, 'from_date' => '2010-06-01', 'to_date' => '2010-06-01',
                'grid_ref' => 'SJ1234', 'recorded_by' => 'Bird recorder', 'data_source_id' => 90,
                'identification_verification_status' => 'V',
            ]);
            $db->table('geographic_regions_occurrences')->insert(['occurrence_id' => 10, 'geographic_region_id' => 1]);
            $this->assertSame([5], $this->ids(['year' => '2010', 'geographic_region_id' => '1', 'rank_id' => '5']));
            $this->assertSame([5, 62], $this->ids(['taxon_id' => '5']));
        } finally {
            config('Import')->taxonRanks = $originalRanks;
            foreach (['taxa', 'occurrences'] as $table) {
                $db->query('ALTER TABLE ' . $db->prefixTable($table) . ' DROP COLUMN class_id');
            }
            $db->resetDataCache();
        }
    }

    /**
     * Empty presentation configuration hides classifications without hiding rank selection.
     */
    public function testEmptyClassificationConfiguration(): void
    {
        config(TaxonSearch::class)->classificationRanks = '';
        $page = (new TaxonSearchService())->page([], true);
        $this->assertSame([], $page['classifications']);
        $this->assertNotEmpty($page['ranks']);
    }

    /**
     * Staff pages and lookup require authentication and expose no downloads.
     */
    public function testPagesAndLookup(): void
    {
        $this->get('taxon-search?taxon_name=Agrotis')->assertRedirect();
        /** @var UserModel $users */
        $users = model(setting('Auth.userProvider'));
        $user = $users->createNewUser([
            'username' => 'search-manager', 'email' => 'search-manager@example.com', 'password' => 'Password123!',
        ]);
        $users->save($user);
        $user = $users->findById($users->getInsertID());
        $user->activate();
        $users->save($user);
        $user->addGroup('manager');
        $this->actingAs($user);
        $this->withSession($_SESSION);
        $initial = $this->get('taxa?q=Agrotis');
        $initial->assertStatus(200);
        $this->assertMatchesRegularExpression('/<details id="advanced-search"\s*>/', $initial->getBody());
        $document = new \DOMDocument();
        $document->loadHTML($initial->getBody(), LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $this->assertSame(1, $xpath->query(
            '//details[@id="advanced-search"]/div[contains(@class, "border")'
            . ' and contains(@class, "rounded-3") and contains(@class, "bg-body-tertiary")]'
        )->length);
        $this->assertSame(0, $xpath->query('//details//input[@name="q"]')->length);
        $this->assertSame(1, $xpath->query('//details[@id="advanced-search"]//input[@name="year"]')->length);
        $this->assertSame(1, $xpath->query('//details[@id="advanced-search"]//select[@name="rank_id"]')->length);
        $this->assertSame(1, $xpath->query('//details[@id="advanced-search"]//input[@name="taxon_name"]')->length);
        $this->assertSame(1, $xpath->query('//details[@id="advanced-search"]//select[@name="blocked"]')->length);
        $page = $this->get('taxa?year=2010&family_id=20&rank_id=30');
        $page->assertStatus(200);
        $page->assertSee('Agrotis');
        $page->assertSee('Taxon (including descendants)');
        $this->assertStringContainsString('<details id="advanced-search" open>', $page->getBody());
        $page->assertDontSee('Download');
        $this->get('taxa?year=2012-2010')->assertStatus(400);
        $this->get('taxa?sort[]=bad')->assertStatus(400);
        $occurrences = $this->get('occurrences?year=2010');
        $occurrences->assertStatus(200);
        $this->assertStringContainsString('border rounded-3 bg-body-tertiary p-3 mx-0 mt-0', $occurrences->getBody());
        $occurrences->assertSee('East region; West region');
        $occurrences->assertDontSee('name="rank_id"');
        $lookup = $this->get('taxon-search?taxon_name=Old%20turnip&rank_id=40');
        $lookup->assertStatus(200);
        $lookup->assertSee('Old turnip name');
        $this->get('taxon-search?taxon_name=x')->assertStatus(400);
        $malicious = '<script>alert("search")</script>';
        db_connect()->table('taxa')->where('id', 40)->update(['scientific_name' => $malicious]);
        $escaped = $this->get('taxa?' . http_build_query(['q' => $malicious]));
        $escaped->assertStatus(200);
        $this->assertStringNotContainsString($malicious, $escaped->getBody());
        $this->assertStringContainsString('&lt;script&gt;', $escaped->getBody());
    }
}
