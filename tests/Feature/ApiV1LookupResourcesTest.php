<?php

namespace Tests;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class ApiV1LookupResourcesTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureLookupTables();

        $this->seedLookupData();
    }

    public function testDataSourcesListReturnsEnvelope(): void
    {
        $result = $this->get('api/v1/data-sources?abbr[eq]=NBN');

        $result->assertStatus(200);
        $result->assertHeader('Content-Type', 'application/json; charset=UTF-8');

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertIsArray($json);
        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('meta', $json);
        $this->assertArrayHasKey('links', $json);
        $this->assertSame('NBN', $json['data'][0]['abbr']);
    }

    public function testTaxonGroupsListSupportsSortFilterAndPagination(): void
    {
        $result = $this->get('api/v1/taxon-groups?friendly[contains]=bee&sort=-title&limit=1&offset=0');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(1, $json['meta']['limit']);
        $this->assertSame(0, $json['meta']['offset']);
        $this->assertSame(1, $json['meta']['count']);
        $this->assertSame('Bees', $json['data'][0]['title']);
    }

    public function testTaxonGroupShowReturnsSingleObject(): void
    {
        $result = $this->get('api/v1/taxon-groups/bees');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('bees', $json['external_key']);
        $this->assertSame('Bees', $json['title']);
    }

    public function testTaxonRanksListSupportsSortFilterAndPagination(): void
    {
        $result = $this->get('api/v1/taxon-ranks?abbr[contains]=sp&sort=-rank&limit=1&offset=0');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(1, $json['meta']['limit']);
        $this->assertSame(0, $json['meta']['offset']);
        $this->assertSame(1, $json['meta']['count']);
        $this->assertSame('Species', $json['data'][0]['rank']);
    }

    public function testTaxonRankShowReturnsSingleObject(): void
    {
        $result = $this->get('api/v1/taxon-ranks/sp');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('sp', $json['abbr']);
        $this->assertSame('Species', $json['rank']);
    }

    public function testRecordingSchemesListSupportsFilterSortAndPagination(): void
    {
        $result = $this->get('api/v1/recording-schemes?title[contains]=scheme&sort=-title&limit=1&offset=0');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(1, $json['meta']['limit']);
        $this->assertSame(0, $json['meta']['offset']);
        $this->assertSame(1, $json['meta']['count']);
        $this->assertSame('SCHEME-0002', $json['data'][0]['external_key']);
    }

    public function testRecordingSchemeShowReturnsSingleObject(): void
    {
        $result = $this->get('api/v1/recording-schemes/SCHEME-0001');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('SCHEME-0001', $json['external_key']);
        $this->assertSame('Alpha scheme', $json['title']);
    }

    public function testGeographicRegionsListSupportsJoinedFilter(): void
    {
        $result = $this->get('api/v1/geographic-regions?data_source_abbr[eq]=NBN&sort=higher_geography_identifier');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(1, $json['meta']['count']);
        $this->assertSame(12, $json['data'][0]['higher_geography_identifier']);
        $this->assertSame('NBN', $json['data'][0]['data_source_abbr']);
    }

    public function testGeographicRegionShowReturnsSingleObject(): void
    {
        $result = $this->get('api/v1/geographic-regions/13');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(13, $json['higher_geography_identifier']);
        $this->assertSame('North Hampshire', $json['higher_geography']);
        $this->assertSame('iRecord', $json['data_source_abbr']);
    }

    public function testTaxaListSupportsFiltersAndExcludesBlocked(): void
    {
        $result = $this->get('api/v1/taxa?scientific_name[contains]=bombus');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(1, $json['meta']['count']);
        $this->assertSame('NHMSYS0021054498', $json['data'][0]['taxon_identifier']);
        $this->assertSame('bees', $json['data'][0]['taxon_group_external_key']);
        $this->assertSame('SCHEME-0001', $json['data'][0]['recording_scheme_external_key']);
    }

    public function testTaxonShowReturnsNotFoundForBlockedTaxon(): void
    {
        $result = $this->get('api/v1/taxa/NHMSYS0099999999');

        $result->assertStatus(404);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('Resource not found', $json['title']);
    }

    public function testTaxonNamesListSupportsTaxonIdentifierFilter(): void
    {
        $result = $this->get('api/v1/taxon-names?taxon_identifier[eq]=NHMSYS0021054498&sort=name');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(2, $json['meta']['count']);
        $this->assertSame('NHMSYS0021054498', $json['data'][0]['taxon_identifier']);
    }

    public function testTaxonNameShowReturnsSingleObject(): void
    {
        $result = $this->get('api/v1/taxon-names/3d77f8e7-e2e8-4d74-9d4d-cff4d11130e8');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('3d77f8e7-e2e8-4d74-9d4d-cff4d11130e8', $json['uuid']);
        $this->assertSame('NHMSYS0021054498', $json['taxon_identifier']);
    }

    public function testTaxonNamesExcludeBlockedTaxa(): void
    {
        $result = $this->get('api/v1/taxon-names?taxon_identifier[eq]=NHMSYS0099999999');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(0, $json['meta']['count']);
    }

    public function testOccurrencesListSupportsTaxonFilterAndExcludesBlocked(): void
    {
        $result = $this->get('api/v1/occurrences?taxon_identifier[eq]=NHMSYS0021054498');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(1, $json['meta']['count']);
        $this->assertSame('NBN:123456789', $json['data'][0]['unique_key']);
        $this->assertSame('NHMSYS0021054498', $json['data'][0]['taxon_identifier']);
    }

    public function testOccurrencesListSupportsHigherGeographyIdentifierFilter(): void
    {
        $result = $this->get('api/v1/occurrences?higher_geography_identifier[eq]=12');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(1, $json['meta']['count']);
        $this->assertSame(12, $json['data'][0]['higher_geography_identifier']);
    }

    public function testOccurrenceShowReturnsSingleObject(): void
    {
        $result = $this->get('api/v1/occurrences/NBN:123456789');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('NBN:123456789', $json['unique_key']);
        $this->assertSame('3d77f8e7-e2e8-4d74-9d4d-cff4d11130e8', $json['taxon_name_uuid']);
    }

    public function testOccurrenceShowReturnsNotFoundForBlockedOccurrence(): void
    {
        $result = $this->get('api/v1/occurrences/NBN:BLOCKED001');

        $result->assertStatus(404);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('Resource not found', $json['title']);
    }

    public function testGridSquareStatsListSupportsRegionFilterAndPagination(): void
    {
        $result = $this->get('api/v1/grid-square-stats?geographic_region_identifier[eq]=12&sort=square&limit=10&offset=0');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(1, $json['meta']['count']);
        $this->assertSame('SU1234', $json['data'][0]['square']);
        $this->assertSame(12, $json['data'][0]['geographic_region_identifier']);
    }

    public function testGridSquareStatsShowReturnsSingleObject(): void
    {
        $result = $this->get('api/v1/grid-square-stats/11111111-1111-4111-8111-111111111111');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('11111111-1111-4111-8111-111111111111', $json['uuid']);
        $this->assertSame(12, $json['geographic_region_identifier']);
    }

    public function testTaxonStatsListSupportsTaxonFilterAndExcludesBlockedTaxa(): void
    {
        $result = $this->get('api/v1/taxon-stats?taxon_identifier[eq]=NHMSYS0021054498');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(1, $json['meta']['count']);
        $this->assertSame('NHMSYS0021054498', $json['data'][0]['taxon_identifier']);
    }

    public function testTaxonStatsShowReturnsNotFoundForBlockedTaxonStats(): void
    {
        $result = $this->get('api/v1/taxon-stats/44444444-4444-4444-8444-444444444444');

        $result->assertStatus(404);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('Resource not found', $json['title']);
    }

    public function testTaxonYearStatsListSupportsYearAndRegionFilters(): void
    {
        $result = $this->get('api/v1/taxon-year-stats?year[eq]=2024&geographic_region_identifier[eq]=12');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(1, $json['meta']['count']);
        $this->assertSame(2024, $json['data'][0]['year']);
        $this->assertSame(12, $json['data'][0]['geographic_region_identifier']);
    }

    public function testTaxonYearStatsShowReturnsSingleObject(): void
    {
        $result = $this->get('api/v1/taxon-year-stats/55555555-5555-4555-8555-555555555555');

        $result->assertStatus(200);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('55555555-5555-4555-8555-555555555555', $json['uuid']);
        $this->assertSame('NHMSYS0021054498', $json['taxon_identifier']);
    }

    public function testInvalidFilterReturnsProblemJson(): void
    {
        $result = $this->get('api/v1/taxon-groups?blocked[eq]=1');

        $result->assertStatus(400);
        $result->assertHeader('Content-Type', 'application/problem+json; charset=UTF-8');

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(400, $json['status']);
        $this->assertSame('Invalid filter parameter', $json['title']);
    }

    public function testMissingResourceReturnsNotFoundProblem(): void
    {
        $result = $this->get('api/v1/data-sources/UNKNOWN');

        $result->assertStatus(404);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('Resource not found', $json['title']);
    }

    private function seedLookupData(): void
    {
        $db = db_connect();

        $db->table('taxon_stats')->emptyTable();
        $db->table('taxon_year_stats')->emptyTable();
        $db->table('geographic_regions')->emptyTable();
        $db->table('geographic_regions_occurrences')->emptyTable();
        $db->table('grid_square_stats')->emptyTable();
        $db->table('occurrences')->emptyTable();
        $db->table('recording_schemes')->emptyTable();
        $db->table('taxon_names')->emptyTable();
        $db->table('taxa')->emptyTable();
        $db->table('taxon_groups')->emptyTable();
        $db->table('taxon_ranks')->emptyTable();
        $db->table('data_sources')->emptyTable();

        $now = date('Y-m-d H:i:s');

        $db->table('data_sources')->insertBatch([
            [
                'id' => 1,
                'abbr' => 'NBN',
                'title' => 'NBN Atlas',
                'url' => 'https://nbnatlas.org',
            ],
            [
                'id' => 2,
                'abbr' => 'iRecord',
                'title' => 'iRecord',
                'url' => 'https://irecord.org.uk',
            ],
        ]);

        $db->table('taxon_groups')->insertBatch([
            [
                'id' => 1,
                'title' => 'Bees',
                'friendly' => 'Bee species',
                'external_key' => 'bees',
                'indicia_taxon_group_id' => 10,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'title' => 'Birds',
                'friendly' => 'Bird species',
                'external_key' => 'birds',
                'indicia_taxon_group_id' => 11,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
        ]);

        $db->table('recording_schemes')->insertBatch([
            [
                'id' => 1,
                'external_key' => 'SCHEME-0001',
                'title' => 'Alpha scheme',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'external_key' => 'SCHEME-0002',
                'title' => 'Beta scheme',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
        ]);

        $db->table('geographic_regions')->insertBatch([
            [
                'id' => 1,
                'higher_geography_identifier' => 12,
                'higher_geography' => 'South Hampshire',
                'location_type' => 'Vice-county',
                'data_source_id' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'higher_geography_identifier' => 13,
                'higher_geography' => 'North Hampshire',
                'location_type' => 'Vice-county',
                'data_source_id' => 2,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
        ]);

        $db->table('taxon_ranks')->insertBatch([
            [
                'id' => 1,
                'rank' => 'Genus',
                'abbr' => 'gen',
                'sort_order' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'rank' => 'Species',
                'abbr' => 'sp',
                'sort_order' => 2,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
        ]);

        $db->table('taxa')->insertBatch([
            [
                'id' => 1,
                'taxon_identifier' => 'NHMSYS0021054498',
                'scientific_name_identifier' => 'TVK-001',
                'scientific_name' => 'Bombus terrestris',
                'scientific_name_authorship' => 'Linnaeus, 1758',
                'vernacular_name' => 'Buff-tailed Bumblebee',
                'taxon_group_id' => 1,
                'taxon_rank_id' => 2,
                'id_difficulty' => 2,
                'recording_scheme_id' => 1,
                'conservation_status' => 'LC',
                'taxon_remarks' => 'Common and widespread.',
                'rarity_group_name' => 'common',
                'blocked' => 0,
                'blocked_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'taxon_identifier' => 'NHMSYS0099999999',
                'scientific_name_identifier' => 'TVK-BLOCKED-1',
                'scientific_name' => 'Bombus blockedus',
                'scientific_name_authorship' => null,
                'vernacular_name' => 'Blocked Bee',
                'taxon_group_id' => 1,
                'taxon_rank_id' => 2,
                'id_difficulty' => 3,
                'recording_scheme_id' => 1,
                'conservation_status' => 'EN',
                'taxon_remarks' => null,
                'rarity_group_name' => 'scarce',
                'blocked' => 1,
                'blocked_reason' => 'Sensitive record',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
        ]);

        $db->table('taxon_names')->insertBatch([
            [
                'id' => 1,
                'uuid' => '3d77f8e7-e2e8-4d74-9d4d-cff4d11130e8',
                'taxon_id' => 1,
                'name' => 'Bombus terrestris',
                'given_name_identifier' => 'TVK-001',
                'accepted' => 1,
                'scientific' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'uuid' => 'f54da6a0-5f0b-4de2-a10a-2693b193f5f2',
                'taxon_id' => 1,
                'name' => 'Buff-tailed Bumblebee',
                'given_name_identifier' => 'TVK-002',
                'accepted' => 1,
                'scientific' => 0,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
            [
                'id' => 3,
                'uuid' => '82cbf7f8-3f42-42ff-82e3-ac39a9402fd0',
                'taxon_id' => 2,
                'name' => 'Bombus blockedus',
                'given_name_identifier' => 'TVK-BLOCKED-1',
                'accepted' => 1,
                'scientific' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
        ]);

        $db->table('occurrences')->insertBatch([
            [
                'id' => 1,
                'unique_key' => 'NBN:123456789',
                'taxon_id' => 1,
                'taxon_name_id' => 1,
                'from_date' => '2024-05-11',
                'to_date' => '2024-05-11',
                'grid_ref' => 'SU123456',
                'grid_ref_2km' => 'SU15A',
                'locality' => 'Titchfield Haven',
                'recorded_by' => 'J. Smith',
                'identified_by' => 'A. Brown',
                'identification_verification_status' => 'V',
                'sex' => 'female',
                'life_stage' => 'adult',
                'organism_quantity' => '1',
                'data_source_id' => 1,
                'blocked' => 0,
                'blocked_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'unique_key' => 'NBN:BLOCKED001',
                'taxon_id' => 1,
                'taxon_name_id' => 2,
                'from_date' => '2024-06-12',
                'to_date' => '2024-06-12',
                'grid_ref' => 'SU654321',
                'grid_ref_2km' => 'SU65B',
                'locality' => 'Blocked Site',
                'recorded_by' => 'D. Block',
                'identified_by' => null,
                'identification_verification_status' => 'C',
                'sex' => null,
                'life_stage' => null,
                'organism_quantity' => '1',
                'data_source_id' => 1,
                'blocked' => 1,
                'blocked_reason' => 'Sensitive occurrence',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
        ]);

        $db->table('geographic_regions_occurrences')->insertBatch([
            [
                'geographic_region_id' => 1,
                'occurrence_id' => 1,
            ],
            [
                'geographic_region_id' => 2,
                'occurrence_id' => 2,
            ],
        ]);

        $db->table('grid_square_stats')->insertBatch([
            [
                'id' => 1,
                'uuid' => '11111111-1111-4111-8111-111111111111',
                'square' => 'SU1234',
                'geographic_region_id' => 1,
                'easting' => 412300,
                'northing' => 112300,
                'partial' => 0,
                'occurrences_count' => 12,
                'species_count' => 7,
            ],
            [
                'id' => 2,
                'uuid' => '22222222-2222-4222-8222-222222222222',
                'square' => 'SU5678',
                'geographic_region_id' => 2,
                'easting' => 456700,
                'northing' => 156700,
                'partial' => 1,
                'occurrences_count' => 4,
                'species_count' => 3,
            ],
        ]);

        $db->table('taxon_stats')->insertBatch([
            [
                'id' => 1,
                'uuid' => '33333333-3333-4333-8333-333333333333',
                'taxon_id' => 1,
                'geographic_region_id' => 1,
                'occurrences_count' => 20,
                'grid_square_count' => 5,
                'first_record_date' => '2018-01-02',
                'last_record_date' => '2025-06-30',
                'first_recorder' => 'A. Recorder',
                'last_recorder' => 'B. Recorder',
                'first_verified_record_date' => '2018-01-05',
                'last_verified_record_date' => '2025-07-01',
                'first_verified_recorder' => 'V. One',
                'last_verified_recorder' => 'V. Two',
            ],
            [
                'id' => 2,
                'uuid' => '44444444-4444-4444-8444-444444444444',
                'taxon_id' => 2,
                'geographic_region_id' => 2,
                'occurrences_count' => 2,
                'grid_square_count' => 1,
                'first_record_date' => '2022-04-02',
                'last_record_date' => '2024-04-02',
                'first_recorder' => 'Blocked First',
                'last_recorder' => 'Blocked Last',
                'first_verified_record_date' => '2022-04-03',
                'last_verified_record_date' => '2024-04-03',
                'first_verified_recorder' => 'Blocked V1',
                'last_verified_recorder' => 'Blocked V2',
            ],
        ]);

        $db->table('taxon_year_stats')->insertBatch([
            [
                'id' => 1,
                'uuid' => '55555555-5555-4555-8555-555555555555',
                'taxon_id' => 1,
                'geographic_region_id' => 1,
                'year' => 2024,
                'occurrences_count' => 9,
                'grid_square_count' => 3,
            ],
            [
                'id' => 2,
                'uuid' => '66666666-6666-4666-8666-666666666666',
                'taxon_id' => 2,
                'geographic_region_id' => 2,
                'year' => 2024,
                'occurrences_count' => 1,
                'grid_square_count' => 1,
            ],
        ]);
    }

    private function ensureLookupTables(): void
    {
        $db = db_connect();
        $prefix = $db->getPrefix();

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'data_sources (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            abbr VARCHAR(10) NOT NULL,
            title VARCHAR(100) NOT NULL,
            url VARCHAR(100) NOT NULL
        )');

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'taxon_groups (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title VARCHAR(200) NOT NULL,
            friendly VARCHAR(200) NULL,
            external_key VARCHAR(100) NULL,
            indicia_taxon_group_id INTEGER NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL
        )');

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'taxon_ranks (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            rank VARCHAR(100) NOT NULL,
            abbr VARCHAR(10) NULL,
            sort_order INTEGER NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL
        )');

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'recording_schemes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            external_key VARCHAR(16) NOT NULL,
            title VARCHAR(100) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL
        )');

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'geographic_regions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            higher_geography_identifier INTEGER NOT NULL,
            higher_geography VARCHAR(100) NOT NULL,
            location_type VARCHAR(100) NOT NULL,
            data_source_id INTEGER NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL
        )');

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'taxa (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            taxon_identifier VARCHAR(100) NOT NULL,
            scientific_name_identifier VARCHAR(100) NOT NULL,
            scientific_name VARCHAR(200) NOT NULL,
            scientific_name_authorship VARCHAR(100) NULL,
            vernacular_name VARCHAR(200) NOT NULL,
            taxon_rank_id INTEGER NOT NULL,
            taxon_group_id INTEGER NOT NULL,
            id_difficulty INTEGER NULL,
            recording_scheme_id INTEGER NULL,
            conservation_status VARCHAR(10) NULL,
            taxon_remarks TEXT NULL,
            rarity_group_name VARCHAR(100) NOT NULL,
            blocked INTEGER NOT NULL DEFAULT 0,
            blocked_reason TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL
        )');

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'taxon_names (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid VARCHAR(36) NOT NULL,
            taxon_id INTEGER NOT NULL,
            name VARCHAR(200) NOT NULL,
            given_name_identifier VARCHAR(100) NOT NULL,
            accepted INTEGER NOT NULL DEFAULT 0,
            scientific INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL
        )');

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'occurrences (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            unique_key VARCHAR(100) NOT NULL,
            taxon_id INTEGER NOT NULL,
            taxon_name_id INTEGER NOT NULL,
            from_date DATE NULL,
            to_date DATE NULL,
            grid_ref VARCHAR(20) NOT NULL,
            grid_ref_2km VARCHAR(5) NOT NULL,
            locality VARCHAR(255) NULL,
            recorded_by VARCHAR(255) NOT NULL,
            identified_by VARCHAR(255) NULL,
            identification_verification_status VARCHAR(2) NOT NULL,
            sex VARCHAR(20) NULL,
            life_stage VARCHAR(20) NULL,
            organism_quantity VARCHAR(20) NULL,
            data_source_id INTEGER NOT NULL,
            blocked INTEGER NOT NULL DEFAULT 0,
            blocked_reason TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NULL,
            deleted_at DATETIME NULL
        )');

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'geographic_regions_occurrences (
            geographic_region_id INTEGER NOT NULL,
            occurrence_id INTEGER NOT NULL
        )');

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'grid_square_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid VARCHAR(36) NOT NULL,
            square VARCHAR(12) NOT NULL,
            geographic_region_id INTEGER NULL,
            easting INTEGER NOT NULL,
            northing INTEGER NOT NULL,
            partial INTEGER NOT NULL DEFAULT 0,
            occurrences_count INTEGER NOT NULL DEFAULT 0,
            species_count INTEGER NOT NULL DEFAULT 0
        )');

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'taxon_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid VARCHAR(36) NOT NULL,
            taxon_id INTEGER NOT NULL,
            geographic_region_id INTEGER NULL,
            occurrences_count INTEGER NOT NULL DEFAULT 0,
            grid_square_count INTEGER NOT NULL DEFAULT 0,
            first_record_date DATE NOT NULL,
            last_record_date DATE NOT NULL,
            first_recorder VARCHAR(255) NOT NULL,
            last_recorder VARCHAR(255) NOT NULL,
            first_verified_record_date DATE NOT NULL,
            last_verified_record_date DATE NOT NULL,
            first_verified_recorder VARCHAR(255) NOT NULL,
            last_verified_recorder VARCHAR(255) NOT NULL
        )');

        $db->query('CREATE TABLE IF NOT EXISTS ' . $prefix . 'taxon_year_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid VARCHAR(36) NOT NULL,
            taxon_id INTEGER NOT NULL,
            geographic_region_id INTEGER NULL,
            year INTEGER NOT NULL,
            occurrences_count INTEGER NOT NULL DEFAULT 0,
            grid_square_count INTEGER NOT NULL DEFAULT 0
        )');
    }
}
