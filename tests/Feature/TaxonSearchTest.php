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
            'taxon_ranks', 'taxon_groups', 'recording_schemes', 'geographic_regions'] as $table) {
            $db->table($table)->emptyTable();
        }
        foreach ([1 => 'Family scheme', 2 => 'Species scheme', 3 => 'Unused scheme', 4 => 'Deleted scheme'] as $id => $title) {
            $db->table('recording_schemes')->insert([
                'id' => $id, 'title' => $title, 'external_key' => 'SCHEME-' . $id,
                'deleted_at' => $id === 4 ? '2020-01-01 00:00:00' : null,
            ]);
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
        $db->table('taxa')->where('id', 20)->update(['recording_scheme_id' => 1]);
        $db->table('taxa')->where('id', 40)->update(['recording_scheme_id' => 2]);
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
     * Build one condition using the public bookmark contract.
     *
     * @param string $field Available field key.
     * @param string $operator Typed operator.
     * @param string|list<string> $value Scalar or any-of choices.
     * @param string $upper Inclusive range endpoint.
     * @return array<string, mixed> Submitted row.
     */
    private function condition(string $field, string $operator, string|array $value = '', string $upper = ''): array
    {
        return ['field' => $field, 'operator' => $operator, 'value' => $value, 'upper' => $upper];
    }

    /**
     * Seed derived and decision-only scopes without running background rebuilds.
     *
     * @param int $taxon Taxon owning the stored row.
     * @param int|null $region Region, or global.
     * @param array<string, mixed> $values Measurements and curated decisions.
     */
    private function statistics(int $taxon, ?int $region, array $values): void
    {
        db_connect()->table('taxon_stats')->insert($values + [
            'uuid' => \App\Services\Stats\TaxonStatsIdentity::uuidForScope($taxon, $region),
            'taxon_id' => $taxon, 'geographic_region_id' => $region,
            'occurrences_count' => 0, 'grid_square_count' => 0,
            'first_recorder' => '', 'last_recorder' => '',
            'first_verified_recorder' => '', 'last_verified_recorder' => '',
        ]);
    }

    /**
     * Scope and three-state key-species decisions agree on both indexes without inheritance.
     */
    public function testScopedKeySpeciesAndMissingStatisticsOnBothIndexes(): void
    {
        $this->statistics(40, null, ['key_species' => 1, 'occurrences_count' => 12]);
        $this->statistics(40, 1, ['key_species' => 0]);
        $this->statistics(41, 1, ['key_species' => 1, 'occurrences_count' => 3]);
        foreach ([
            ['global', '1', [40], [2, 4, 5, 6]],
            ['1', '1', [41], [1]], ['1', '0', [40], [2, 4, 5, 6]],
        ] as [$scope, $flag, $taxa, $occurrences]) {
            $input = ['stats_scope' => $scope,
                'conditions' => [$this->condition('stats__key_species', 'in', [$flag])]];
            $this->assertSame($taxa, $this->ids($input));
            $this->assertSame($occurrences, $this->ids($input, false));
        }
        $unset = ['stats_scope' => '1', 'conditions' => [$this->condition('stats__key_species', 'empty')]];
        $this->assertSame([10, 20, 21, 30, 31, 42, 50, 51], $this->ids($unset));
        $this->assertSame([3, 8, 9], $this->ids($unset, false));
        $this->assertSame([40], $this->ids(['stats_scope' => '1',
            'conditions' => [$this->condition('stats__occurrences_count', 'eq', '0')]]));
        $this->assertCount(10, $this->ids(['stats_scope' => '1']));
        $this->assertSame([], $this->ids(['stats_scope' => '1', 'conditions' => [
            $this->condition('stats__available', 'eq', '0'),
            $this->condition('stats__occurrences_count', 'eq', '0'),
        ]]));
        $input = ['stats_scope' => '1', 'conditions' => [
            $this->condition('stats__key_species', 'in', ['0']),
            $this->condition('geographic_region_id', 'eq', '2'),
            $this->condition('year', 'eq', '2010'),
        ]];
        $this->assertSame([40], $this->ids($input));
        $this->assertSame([], $this->ids($input, false));
        $fields = (new TaxonSearchService())->fields(false);
        $this->assertArrayNotHasKey('stats__key_species_comment', $fields);
        $this->assertArrayNotHasKey('stats__key_species_decision_date', $fields);
    }

    /**
     * Numeric/date boundaries preserve NULL, zero and full precision in ID-only queries too.
     */
    public function testStatisticsRangesDatesAndIdOnlyQueries(): void
    {
        $this->statistics(40, null, ['occurrences_count' => 5, 'occupied_square_score' => 100,
            'occupied_square_percentage' => 0, 'mean_occurrences_per_year' => 1.2345,
            'last_record_date' => '2010-12-31', 'frequency_trend_state' => 'decreasing']);
        $this->statistics(41, null, ['occupied_square_score' => 0]);
        $input = ['stats_scope' => 'global', 'conditions' => [
            $this->condition('stats__occupied_square_score', 'between', '0', '100'),
            $this->condition('stats__mean_occurrences_per_year', 'gte', '1.2345'),
            $this->condition('stats__last_record_date', 'lte', '2010-12-31'),
        ]];
        $search = new TaxonSearchService();
        foreach ([true, false] as $taxa) {
            $expected = $taxa ? [40] : [2, 4, 5, 6];
            $this->assertSame($expected, $this->ids($input, $taxa));
            $criteria = $search->criteria($input, $taxa);
            $this->assertSame($expected, array_map('intval', array_column(
                $search->query($criteria, $taxa, null, true)->orderBy($taxa ? 't.id' : 'o.id')
                    ->get()->getResultArray(), 'id'
            )));
        }
        $this->assertSame([41], $this->ids(['conditions' => [
            $this->condition('stats__occupied_square_score', 'eq', '0'),
        ]]));
        $this->assertSame([40], $this->ids(['conditions' => [
            $this->condition('stats__occupied_square_percentage', 'eq', '0'),
        ]]));
        $this->assertContains(31, $this->ids(['conditions' => [
            $this->condition('stats__occupied_square_score', 'empty'),
        ]]));
    }

    /**
     * Effective designations match whole tokens and exclusion belongs to the owning species.
     */
    public function testTypedTaxonPredicatesAndRepeatedConditions(): void
    {
        $db = db_connect();
        $db->table('taxa')->where('id', 40)->update(['gb_red_list' => 'LCX',
            'gb_red_list_override' => 'LC;VU', 'id_difficulty' => 3, 'rarity_category' => 2,
            'exclude_from_rarity_calculations' => 1]);
        $db->table('taxa')->where('id', 41)->update(['gb_red_list' => 'LCX', 'id_difficulty' => 4]);
        $input = ['conditions' => [
            $this->condition('gb_red_list', 'in', ['LC', 'EN']),
            $this->condition('id_difficulty', 'gte', '3'),
            $this->condition('id_difficulty', 'lte', '3'),
        ]];
        $this->assertSame([40], $this->ids($input));
        $this->assertSame([2, 4, 5, 6], $this->ids($input, false));
        $this->assertSame([], $this->ids(['q' => 'Turnip', 'conditions' => [
            $this->condition('gb_red_list', 'in', ['LCX']),
        ]]));
        $this->assertSame([40, 41], $this->ids(['conditions' => [
            $this->condition('exclude_from_rarity_calculations', 'eq', '1'),
        ]]));
        $this->assertSame([], $this->ids(['taxon_id' => '40', 'conditions' => [
            $this->condition('scientific_name', 'eq', 'Other family'),
        ]]));
        $this->assertSame([2, 4, 5, 6], $this->ids(['conditions' => [
            $this->condition('rank_id', 'eq', '40'), $this->condition('taxon_id', 'eq', '40'),
        ]], false));
    }

    /**
     * All live rows constrain one occurrence and remain independent of stored measurements.
     */
    public function testBuilderOccurrenceEvidenceAndAdditionalOccurrenceFields(): void
    {
        $this->assertSame([], $this->ids(['conditions' => [
            $this->condition('year', 'eq', '2012'),
            $this->condition('geographic_region_id', 'eq', '1'),
            $this->condition('rank_id', 'eq', '40'),
        ]]));
        $this->assertSame([1], $this->ids(['conditions' => [
            $this->condition('geographic_region_id', 'eq', '1'),
            $this->condition('geographic_region_id', 'eq', '2'),
        ]], false));
        db_connect()->table('occurrences')->where('id', 2)->update([
            'sex' => 'female', 'life_stage' => 'adult', 'organism_quantity' => 'many',
        ]);
        $this->assertSame([2], $this->ids(['conditions' => [
            $this->condition('sex', 'in', 'female, male'),
            $this->condition('life_stage', 'eq', 'adult'),
            $this->condition('organism_quantity', 'eq', 'many'),
            $this->condition('recorded_name', 'contains', 'segetum'),
        ]], false));
        $this->assertSame([3], $this->ids(['conditions' => [
            $this->condition('unique_key', 'eq', 'SEARCH:3'),
            $this->condition('identification_verification_status', 'in', ['V', 'V1']),
        ]], false));
    }

    /**
     * Malformed rows, invalid scopes and stale references never broaden a query.
     */
    public function testBuilderValidationAndNonJavascriptEditing(): void
    {
        $search = new TaxonSearchService();
        foreach ([
            ['conditions' => 'wrong'], ['stats_scope' => '999'],
            ['filter_form' => '1', 'conditions' => []],
            ['conditions' => [$this->condition('stats__key_species_comment', 'contains', 'text')]],
            ['conditions' => [$this->condition('stats__key_species', 'in', ['false'])]],
            ['conditions' => [$this->condition('stats__occupied_square_score', 'eq', '100.0001')]],
            ['conditions' => [$this->condition('stats__mean_occurrences_per_year', 'eq', '1.23456')]],
            ['conditions' => [$this->condition('stats__last_record_date', 'eq', '2025-02-29')]],
            ['conditions' => [$this->condition('id_difficulty', 'between', '5', '2')]],
            ['conditions' => [$this->condition('taxon_id', 'eq', '999')]],
            ['conditions' => [$this->condition('family_id', 'eq', '40')]],
            ['conditions' => [$this->condition('year', 'eq', '2012-2010')]],
            ['conditions' => [$this->condition('sex', 'eq', 'male')]],
        ] as $input) {
            $criteria = $search->criteria($input, true);
            $this->assertNotEmpty($criteria->errors, json_encode($input));
            $this->assertSame([], $search->query($criteria, true)->get()->getResultArray());
        }
        $input = ['conditions' => [$this->condition('stats__key_species', 'in', '')],
            'filter_action' => 'add'];
        $page = $search->page($input, true);
        $this->assertSame([], $page['errors']);
        $this->assertCount(2, $page['conditionRows']);
        $this->assertSame([], $page['taxa']);
        $page = $search->page(['filter_action' => 'remove:0',
            'conditions' => [$this->condition('taxon_id', 'eq', '40')]], false);
        $this->assertSame([], $page['conditionRows']);
        $page = $search->page(['filter_action' => 'refresh', 'conditions' => [
            ['field' => 'taxon_id', 'operator' => 'eq', 'value' => '', 'text' => 'Old turnip'],
        ]], true);
        $this->assertSame([40], array_column($page['rowMatches'][0], 'id'));
        $summary = $search->summary($search->criteria(['stats_scope' => '1', 'conditions' => [
            $this->condition('stats__key_species', 'in', ['0']),
        ]], false), $search->ordering([], false), false);
        $this->assertContains(['label' => 'Statistics scope', 'value' => 'West region'], $summary);
        $this->assertContains(['label' => 'Statistics: key species', 'value' => 'Includes any No'], $summary);
    }

    /**
     * Checklist values and text predicates agree with effective SQL ordering, including ties.
     */
    public function testGbOverridesDriveChecklistSearchAndOrdering(): void
    {
        $db = db_connect();
        $db->table('taxa')->where('id', 40)->update([
            'gb_red_list' => 'HIDDEN-SOURCE', 'gb_status' => 'HIDDEN-SOURCE',
            'gb_red_list_override' => 'ZZ-OVERRIDE', 'gb_status_override' => 'ZZ-OVERRIDE',
        ]);
        $db->table('taxa')->whereIn('id', [41, 50])->update([
            'gb_red_list' => 'AA-FALLBACK', 'gb_status' => 'AA-FALLBACK',
            'gb_red_list_override' => "\t \n", 'gb_status_override' => '',
        ]);
        $this->assertSame([40], $this->ids(['q' => 'ZZ-OVERRIDE']));
        $this->assertSame([], $this->ids(['q' => 'HIDDEN-SOURCE']));
        $this->assertSame([41, 50], $this->ids(['q' => 'AA-FALLBACK']));
        $search = new TaxonSearchService();
        foreach (['gb_red_list', 'gb_status'] as $field) {
            foreach (['asc' => [41, 50, 40], 'desc' => [40, 50, 41]] as $direction => $expected) {
                $criteria = $search->criteria([], true);
                $query = $search->query($criteria, true)->whereIn('t.id', [40, 41, 50]);
                $search->orderQuery($query, $search->ordering(['sort' => $field, 'direction' => $direction], true), true);
                $this->assertSame($expected[1], (int) (clone $query)->get(1, 1, false)->getRowArray()['id']);
                $rows = $search->hydrate($query->get()->getResultArray(), true);
                $this->assertSame($expected, array_map('intval', array_column($rows, 'id')));
                $this->assertSame($direction === 'asc' ? 'AA-FALLBACK' : 'ZZ-OVERRIDE', $rows[0][$field]);
            }
        }
    }

    /**
     * Share exact-taxon criteria across staff pages, suggestions and export candidates.
     */
    public function testConfiguredAttributeFiltersPreserveArraysWithoutInheritance(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-11-09-100000_CreateTaxonAttributes.php';
        (new \App\Database\Migrations\CreateTaxonAttributes(\Config\Database::forge()))->up();
        $configuration = config(\Config\TaxonAttributes::class);
        $previous = $configuration->attributes;
        $configuration->attributes = [
            'sqs' => ['id' => 24, 'label' => 'SQS', 'type' => 'integer', 'multiValue' => false],
            'habitat' => ['id' => 16, 'label' => 'Habitat', 'type' => 'term', 'multiValue' => true],
        ];
        $db = db_connect();
        try {
            foreach (['taxon_attribute_values', 'taxon_attribute_terms', 'taxon_attributes'] as $table) {
                $db->table($table)->emptyTable();
            }
            $attributes = new \App\Services\TaxonAttributeService($db);
            foreach ([30 => 9, 40 => 1] as $taxonId => $score) {
                $db->transBegin();
                $attributes->replace($taxonId, [
                    'sqs' => ['termlist_id' => null, 'values' => [['value' => $score, 'label' => null]]],
                    'habitat' => ['termlist_id' => 627, 'values' => [
                        ['value' => $taxonId, 'label' => 'Habitat ' . $taxonId],
                        ['value' => $taxonId + 100, 'label' => 'Other habitat ' . $taxonId],
                    ]],
                ]);
                $this->assertTrue($db->transCommit());
            }
            $input = ['attribute__sqs' => ['gte' => '1', 'lte' => '1'],
                'attribute__habitat' => ['in' => ['indicia:40', 'indicia:140']]];
            $this->assertSame([40], $this->ids($input));
            $builderInput = ['conditions' => [
                $this->condition('attribute__sqs', 'between', '1', '1'),
                $this->condition('attribute__habitat', 'in', ['indicia:40', 'indicia:140']),
            ]];
            $this->assertSame([40], $this->ids($builderInput));
            $this->assertSame([2, 4, 5, 6], $this->ids($builderInput, false));
            $this->assertSame([41], $this->ids(['taxon_id' => '40', 'conditions' => [
                $this->condition('attribute__sqs', 'empty'),
            ]]));
            $this->assertSame([30], $this->ids(['attribute__sqs' => '9']));
            $this->assertSame([8], $this->ids(['attribute__sqs' => '9'], false));
            $search = new TaxonSearchService($db);
            $criteria = $search->criteria($input, true);
            $this->assertSame($input['attribute__habitat'], $criteria->values['attribute__habitat']);
            $this->assertSame([40], array_map('intval', array_column(
                $search->query($criteria, true, [30, 40, 41], true)->get()->getResultArray(), 'id'
            )));
            $this->assertSame([40], array_column($search->lookup('Agrotis', $criteria), 'id'));
            $html = view('search/filters', ['page' => $search->page($input, true)]);
            $this->assertStringContainsString('name="conditions[1][value][]"', $html);
            $this->assertStringContainsString('value="indicia:140" selected', $html);
            $invalid = $search->criteria(['attribute__sqs' => ['gte' => '4', 'lte' => '2']], true);
            $this->assertArrayHasKey('attribute__sqs', $invalid->errors);
        } finally {
            $configuration->attributes = $previous;
            foreach (['taxon_attribute_values', 'taxon_attribute_terms', 'taxon_attributes'] as $table) {
                $db->table($table)->emptyTable();
            }
        }
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
     * ID-only capture queries retain every grid predicate while projecting only IDs.
     */
    public function testIdOnlyQueriesMatchGridFilters(): void
    {
        $search = new TaxonSearchService();
        $inputs = [[], ['q' => 'Noctuidae'], ['q' => 'West region'], ['q' => 'Old turnip'],
            ['taxon_name' => 'Agrotis'], ['taxon_id' => '20'], ['family_id' => '20'],
            ['taxon_group_id' => '1'], ['recording_scheme_id' => '1'], ['recording_scheme_id' => '2'],
            ['recording_scheme_id' => '3'], ['blocked' => '0'], ['taxon_blocked' => '0'],
            ['geographic_region_id' => '1'], ['data_source_id' => '90'], ['year' => '2010'],
            ['from_date' => '2010-01-01', 'to_date' => '2010-12-31'],
            ['year' => '2010', 'geographic_region_id' => '1', 'taxon_blocked' => '0']];
        foreach ([true, false] as $taxa) {
            foreach ($inputs as $input) {
                $criteria = $search->criteria($input, $taxa);
                $alias = $taxa ? 't' : 'o';
                $expected = array_column($search->query($criteria, $taxa)->orderBy($alias . '.id')
                    ->get()->getResultArray(), 'id');
                $actual = $search->query($criteria, $taxa, null, true)->orderBy($alias . '.id')
                    ->get()->getResultArray();
                $this->assertSame($expected, array_column($actual, 'id'), json_encode($input));
                foreach ($actual as $row) {
                    $this->assertSame(['id'], array_keys($row));
                }
            }
        }
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
     * Schemes match self or any parent without requiring records or overriding parents.
     */
    public function testRecordingSchemeInheritanceAndCombinedFilters(): void
    {
        $this->assertSame([20, 30, 31, 40, 41, 42, 50, 51], $this->ids(['recording_scheme_id' => '1']));
        $this->assertSame([40, 41], $this->ids(['recording_scheme_id' => '2']));
        $this->assertSame([1, 2, 4, 5, 6], $this->ids(['recording_scheme_id' => '2'], false));
        $this->assertSame([1, 2, 3, 4, 5, 6, 8, 9], $this->ids(['recording_scheme_id' => '1'], false));
        $this->assertSame([40], $this->ids(['recording_scheme_id' => '1', 'year' => '2010',
            'rank_id' => '40', 'taxon_blocked' => '0']));
        $this->assertSame([1], $this->ids(['recording_scheme_id' => '2', 'year' => '2010',
            'geographic_region_id' => '2'], false));
        $this->assertSame([], $this->ids(['recording_scheme_id' => '2', 'taxon_id' => '50']));
        foreach ([true, false] as $taxa) {
            $this->assertSame([], $this->ids(['recording_scheme_id' => '3'], $taxa));
            $search = new TaxonSearchService();
            $summary = $search->summary($search->criteria(['recording_scheme_id' => '1'], $taxa),
                $search->ordering([], $taxa), $taxa);
            $this->assertSame('Family scheme', $summary[0]['value']);
        }
        $search = new TaxonSearchService();
        $this->assertSame([40], array_column($search->lookup('Old turnip',
            $search->criteria(['recording_scheme_id' => '1'], true)), 'id'));
        $this->assertSame([], $search->lookup('Other family',
            $search->criteria(['recording_scheme_id' => '1'], true)));
    }

    /**
     * Non-reporting parents, overlapping roots and cycles use live parent links.
     */
    public function testRecordingSchemeTraversalAndDeletedTaxa(): void
    {
        $db = db_connect();
        $db->table('taxa')->where('id', 20)->update(['recording_scheme_id' => null]);
        $db->table('taxa')->whereIn('id', [50, 51])->update(['recording_scheme_id' => 1]);
        $db->table('taxa')->where('id', 50)->update(['parent_taxon_id' => 51]);
        $this->assertSame([50, 51], $this->ids(['recording_scheme_id' => '1']));
        $this->assertSame([9], $this->ids(['recording_scheme_id' => '1'], false));
        $db->table('taxa')->where('id', 51)->update(['recording_scheme_id' => null]);
        $db->table('taxa')->where('id', 50)->update(['deleted_at' => '2020-01-01']);
        $this->assertSame([], $this->ids(['recording_scheme_id' => '1']));
        $db->table('taxa')->where('id', 40)->update(['deleted_at' => '2020-01-01']);
        $this->assertSame([], $this->ids(['recording_scheme_id' => '2'], false));
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
     * Both national designation lists are searchable and sortable without extra joins.
     */
    public function testDesignationListsInChecklistSearchAndOrdering(): void
    {
        db_connect()->table('taxa')->where('id', 40)->update([
            'gb_red_list' => 'LC;VU', 'gb_status' => 'Nationally Rare;Nb',
        ]);
        $this->assertSame([40], $this->ids(['q' => 'Nationally Rare']));
        $this->assertSame([40], $this->ids(['q' => 'LC;VU']));
        $search = new TaxonSearchService();
        $page = $search->page(['q' => 'Nationally Rare', 'sort' => 'gb_status'], true);
        $this->assertSame('LC;VU', $page['taxa'][0]['gb_red_list']);
        $this->assertSame('Nationally Rare;Nb', $page['taxa'][0]['gb_status']);
        $this->assertSame('GB Red List', $page['columnLabels']['gb_red_list']);
        $this->assertSame('GB Status', $page['columnLabels']['gb_status']);
        $this->assertSame([], $search->ordering(['sort' => 'gb_red_list'], true)['errors']);
        $this->assertNotEmpty($search->ordering(['sort' => 'conservation_status'], true)['errors']);
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
            ['recording_scheme_id' => '999'], ['recording_scheme_id' => '4'],
            ['recording_scheme_id' => ['1']], ['recording_scheme_id' => 'bad'],
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
     * Staff pages and lookup require authentication and expose applied-state CSV downloads.
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
        foreach (['year', 'rank_id', 'taxon_id', 'blocked', 'recording_scheme_id'] as $field) {
            $this->assertSame(1, $xpath->query('//details//select[@data-condition-field]/optgroup/option[@value="' . $field . '"]')->length);
        }
        foreach (['taxa', 'occurrences'] as $resource) {
            $schemePage = $this->get($resource . '?recording_scheme_id=2');
            $schemePage->assertStatus(200);
            $schemePage->assertSee('Recording scheme (including parents)');
            $schemePage->assertDontSee('Deleted scheme');
            $this->assertStringContainsString('<details id="advanced-search" open>', $schemePage->getBody());
            $document->loadHTML($schemePage->getBody(), LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($document);
            $this->assertSame(1, $xpath->query(
                '//select[@name="conditions[0][value]"]/option[@value="2" and @selected]'
            )->length);
            $this->get($resource . '?recording_scheme_id=4')->assertStatus(400);
            $builder = $this->get($resource . '?' . http_build_query(['stats_scope' => '1',
                'conditions' => [$this->condition('stats__key_species', 'empty')]]));
            $builder->assertStatus(200);
            $this->assertStringContainsString('conditions%5B0%5D%5Bfield%5D=stats__key_species', $builder->getBody());
            $this->assertStringContainsString('stats_scope=1', $builder->getBody());
            $document->loadHTML($builder->getBody(), LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($document);
            $this->assertSame(1, $xpath->query(
                '//noscript//button[@name="filter_action" and @value="refresh"]'
            )->length);
            $this->assertSame(0, $xpath->query(
                '//button[@name="filter_action" and @value="refresh" and not(ancestor::noscript)]'
            )->length);
            $names = $this->get($resource . '?' . http_build_query([
                'conditions' => [$this->condition('scientific_name', 'contains', 'Agrotis')],
            ]));
            $names->assertStatus(200);
            $names->assertSee('Enter all or part of a scientific name, then select Search.');
            $names->assertSee('Agrotis segetum');
            $names->assertDontSee('data-taxon-input');
            $picker = $this->get($resource . '?' . http_build_query([
                'filter_action' => 'refresh', 'conditions' => [
                    ['field' => 'taxon_id', 'operator' => 'eq', 'text' => 'Agrotis', 'value' => ''],
                ],
            ]));
            $picker->assertStatus(200);
            $picker->assertSee('Type at least two characters and choose a taxon');
            $document->loadHTML($picker->getBody(), LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($document);
            $this->assertSame(1, $xpath->query(
                '//select[@data-taxon-selection]/option[@value="30"]'
            )->length);
        }
        $page = $this->get('taxa?year=2010&family_id=20&rank_id=30');
        $page->assertStatus(200);
        $page->assertSee('Agrotis');
        $page->assertSee('Taxon (including descendants)');
        $this->assertStringContainsString('<details id="advanced-search" open>', $page->getBody());
        $page->assertSee('Download CSV');
        $this->assertStringContainsString('exports/new?', $page->getBody());
        $this->get('taxa?year=2012-2010')->assertStatus(400);
        $this->get('taxa?sort[]=bad')->assertStatus(400);
        $occurrences = $this->get('occurrences?year=2010');
        $occurrences->assertStatus(200);
        $this->assertStringContainsString('border rounded-3 bg-body-tertiary p-3', $occurrences->getBody());
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
