<?php

namespace Tests;

use App\Models\TaxonModel;
use Config\Auth;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class TaxaPagesTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected function setUp(): void
    {
        parent::setUp();

        \Config\Services::reset();
        $_SESSION = [];
        $_COOKIE = [];
        $this->withSession([]);

        if (function_exists('auth')) {
            try {
                auth()->logout();
            } catch (\Throwable) {
            }
        }

        config(Auth::class)->actions['register'] = null;

        $migrate = service('migrations');
        $migrate->setNamespace(null);
        $migrate->latest();

        $this->seedTaxa();
    }

    public function testListRequiresLogin(): void
    {
        $result = $this->get('taxa');

        $result->assertStatus(302);
        $result->assertRedirect();
    }

    public function testListAllowsManagerRole(): void
    {
        $this->authenticateAs('taxa-manager@example.com', 'manager');

        $result = $this->get('taxa');

        $result->assertStatus(200);
        $result->assertSee('Taxa');
        $result->assertSee('Taxon identifier');
        $result->assertSee('Taxon group');
        $result->assertSee('Bee species');
        $result->assertSee('Blocked');
    }

    public function testListSearchFiltersResults(): void
    {
        $this->authenticateAs('taxa-manager-search@example.com', 'manager');

        $result = $this->get('taxa?q=lucorum');

        $result->assertStatus(200);
        $result->assertSee('Bombus lucorum');
        $result->assertDontSee('Bombus terrestris');
    }

    public function testListSearchFiltersByTaxonGroupTitle(): void
    {
        $this->authenticateAs('taxa-manager-group-search@example.com', 'manager');

        $result = $this->get('taxa?q=Bees');

        $result->assertStatus(200);
        $result->assertSee('Bombus terrestris');
    }

    public function testDetailsShowsAssociatedTaxonNamesTable(): void
    {
        $this->authenticateAs('taxa-detail@example.com', 'manager');

        $result = $this->get('taxa/1');

        $result->assertStatus(200);
        $result->assertSee('Associated taxon names');
        $result->assertSee('Bombus terrestris');
        $result->assertSee('TVK-001');
        $result->assertSee('Buff-tailed Bumblebee');
        $result->assertSee('Species');
        $result->assertSee('Reporting rank');
        $result->assertSee('Immediate accepted parent');
        $result->assertSee('Bees');
        $result->assertSee('Alpha scheme');
    }

    public function testDetailsShowsSeededTaxonMediaCard(): void
    {
        $this->authenticateAs('taxa-detail-media@example.com', 'manager');

        $now = date('Y-m-d H:i:s');
        db_connect()->table('taxon_media')->insert([
            'uuid' => '77777777-7777-4777-8777-777777777777',
            'taxon_id' => 1,
            'original_filename' => 'details-photo.jpg',
            'storage_path' => '1/77777777-7777-4777-8777-777777777777/original.jpg',
            'mime_type' => 'image/jpeg',
            'bytes' => 100,
            'width' => 100,
            'height' => 100,
            'alt_text' => 'Details image',
            'caption' => 'Details caption',
            'attribution' => null,
            'license' => null,
            'sort_order' => 0,
            'is_primary' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $result = $this->get('taxa/1');

        $result->assertStatus(200);
        $result->assertSee('Taxon media');
        $result->assertSee('details-photo.jpg');
        $result->assertSee('Details caption');
    }

    public function testManagerCanUpdateRarityGroupNameAndRemarks(): void
    {
        $this->authenticateAs('taxa-manager-update-text@example.com', 'manager');

        $result = $this->post('taxa/1', [
            'rarity_group_name' => 'Marine mammals',
            'taxon_remarks' => 'Manager edited remarks',
        ]);

        $result->assertStatus(302);
        $result->assertRedirect();

        $taxon = model(TaxonModel::class)->find(1);

        $this->assertSame('Marine mammals', $taxon['rarity_group_name']);
        $this->assertSame('Manager edited remarks', $taxon['taxon_remarks']);
    }

    /**
     * Save global and regional decisions when no derived statistics exist.
     */
    public function testManagerCanSaveGlobalAndRegionalKeySpeciesDecisionsWithoutStats(): void
    {
        $regionId = $this->createTestRegion('TPG-KEY-1', 'Key species test region');
        $this->authenticateAs('taxa-manager-key-species@example.com', 'manager');

        $details = $this->get('taxa/1');
        $details->assertStatus(200);
        $details->assertSee('Key species by scope');
        $details->assertSee('Global');
        $details->assertSee('Key species test region');

        $result = $this->post('taxa/1', [
            'key_species' => [
                'global' => '1',
                (string) $regionId => '0',
            ],
            'key_species_comment' => [
                'global' => 'Important across all regions',
                (string) $regionId => 'Regional decision',
            ],
        ]);

        $result->assertStatus(302);
        $result->assertRedirect();

        $db = db_connect();
        $global = $db->table('taxon_stats')
            ->where('taxon_id', 1)
            ->where('geographic_region_id', null)
            ->get()
            ->getRowArray();
        $regional = $db->table('taxon_stats')
            ->where('taxon_id', 1)
            ->where('geographic_region_id', $regionId)
            ->get()
            ->getRowArray();

        $this->assertSame(1, (int) $global['key_species']);
        $this->assertSame('Important across all regions', $global['key_species_comment']);
        $this->assertSame(date('Y-m-d'), $global['key_species_decision_date']);
        $this->assertSame(0, (int) $regional['key_species']);
        $this->assertSame('Regional decision', $regional['key_species_comment']);
        $this->assertSame(date('Y-m-d'), $regional['key_species_decision_date']);
        $this->assertSame(0, (int) $global['occurrences_count']);
        $this->assertSame(0, (int) $regional['occurrences_count']);
    }

    /**
     * Clear unset decisions and retain or refresh dates according to decision changes.
     */
    public function testNotSetClearsDecisionDateAndCommentAndCommentEditKeepsDate(): void
    {
        $this->authenticateAs('taxa-manager-key-species-date@example.com', 'manager');
        $this->post('taxa/1', [
            'key_species' => ['global' => '1'],
            'key_species_comment' => ['global' => 'Initial rationale'],
        ])->assertStatus(302);

        $db = db_connect();
        $db->table('taxon_stats')
            ->where('taxon_id', 1)
            ->where('geographic_region_id', null)
            ->update(['key_species_decision_date' => '2000-01-01']);

        $this->post('taxa/1', [
            'key_species' => ['global' => '1'],
            'key_species_comment' => ['global' => 'Updated rationale'],
        ])->assertStatus(302);

        $row = $db->table('taxon_stats')
            ->where('taxon_id', 1)
            ->where('geographic_region_id', null)
            ->get()
            ->getRowArray();
        $this->assertSame('Updated rationale', $row['key_species_comment']);
        $this->assertSame('2000-01-01', $row['key_species_decision_date']);

        $this->post('taxa/1', [
            'key_species' => ['global' => '0'],
            'key_species_comment' => ['global' => 'Changed to not key'],
        ])->assertStatus(302);

        $row = $db->table('taxon_stats')
            ->where('taxon_id', 1)
            ->where('geographic_region_id', null)
            ->get()
            ->getRowArray();
        $this->assertSame(0, (int) $row['key_species']);
        $this->assertSame('Changed to not key', $row['key_species_comment']);
        $this->assertSame(date('Y-m-d'), $row['key_species_decision_date']);

        $this->post('taxa/1', [
            'key_species' => ['global' => ''],
            'key_species_comment' => ['global' => 'This must be cleared'],
        ])->assertStatus(302);

        $row = $db->table('taxon_stats')
            ->where('taxon_id', 1)
            ->where('geographic_region_id', null)
            ->get()
            ->getRowArray();
        $this->assertNull($row['key_species']);
        $this->assertNull($row['key_species_comment']);
        $this->assertNull($row['key_species_decision_date']);
    }

    /**
     * Reject key-species decisions submitted for unknown geographic regions.
     */
    public function testManagerCannotSubmitUnknownKeySpeciesRegion(): void
    {
        $this->authenticateAs('taxa-manager-key-species-invalid@example.com', 'manager');

        $result = $this->post('taxa/1', [
            'key_species' => ['999999' => '1'],
            'key_species_comment' => ['999999' => 'Invalid scope'],
        ]);

        $result->assertStatus(302);
        $this->assertSame(0, db_connect()->table('taxon_stats')->countAllResults());
        $this->assertStringContainsString('invalid key-species scope', session('errors')['key_species']);
    }

    /**
     * Prevent a standard user from saving key-species decisions.
     */
    public function testStandardUserCannotSaveKeySpeciesDecisions(): void
    {
        $this->authenticateAs('taxa-standard-key-species@example.com', 'user');

        $this->post('taxa/1', [
            'key_species' => ['global' => '1'],
            'key_species_comment' => ['global' => 'Should not save'],
        ])->assertStatus(302);

        $this->assertSame(0, db_connect()->table('taxon_stats')->countAllResults());
    }

    /**
     * Save independent rarity decisions and display escaped measurements per scope.
     */
    public function testManagerCanCurateSpeciesRarityAndDisplayMeasurements(): void
    {
        $db = db_connect();
        $db->table('taxa')->where('id', 1)->update(['species_id' => 1]);
        $regionId = $this->createTestRegion('TPG-RARITY', 'Rarity test region');
        $this->authenticateAs('taxa-manager-rarity@example.com', 'manager');
        $this->post('taxa/1', [
            'rarity_status' => ['global' => 'common', $regionId => 'rare'],
            'rarity_status_comment' => ['global' => '<script>alert(1)</script>', $regionId => 'Regional evidence'],
        ])->assertStatus(302);
        $service = new \App\Services\Stats\TaxonRarityStatusService();
        $scopes = $service->getForTaxon(1);
        $this->assertSame('common', $scopes['global']['rarity_status']);
        $this->assertSame('rare', $scopes[$regionId]['rarity_status']);
        $this->assertSame(date('Y-m-d'), $scopes['global']['rarity_status_decision_date']);
        $this->assertNull($scopes['global']['occupied_square_score']);
        $this->assertNull($scopes['global']['key_species']);
        $db->table('taxon_stats')->where('id', $scopes['global']['id'])->update([
            'occupied_square_score' => 66.6667, 'occupied_square_percentage' => 0.6,
        ]);
        $page = $this->get('taxa/1');
        $page->assertStatus(200);
        $page->assertSee('Rarity by scope');
        $page->assertSee('66.6667');
        $page->assertSee('0.6000');
        $this->assertStringContainsString('&lt;script&gt;', (string) $page->response()->getBody());
        $this->assertStringNotContainsString('<script>alert(1)</script>', (string) $page->response()->getBody());
        $this->assertSame(4, (int) $db->table('taxa')->where('id', 1)->get()->getRowArray()['rarity_category']);
    }

    /**
     * Keep dates on rationale-only edits, update dates on status changes and clear unset decisions.
     */
    public function testRarityDecisionDateAndClearingLifecycle(): void
    {
        $db = db_connect();
        $db->table('taxa')->where('id', 1)->update(['species_id' => 1]);
        $this->authenticateAs('taxa-manager-rarity-dates@example.com', 'manager');
        $service = new \App\Services\Stats\TaxonRarityStatusService();
        $input = ['rarity_status' => ['global' => 'rare'], 'rarity_status_comment' => ['global' => 'Evidence']];
        $this->post('taxa/1', $input)->assertStatus(302);
        $db->table('taxon_stats')->where('taxon_id', 1)->update(['rarity_status_decision_date' => '2020-01-01']);
        $input['rarity_status_comment']['global'] = 'Updated evidence';
        $this->post('taxa/1', $input)->assertStatus(302);
        $this->assertSame('2020-01-01', $service->getForTaxon(1)['global']['rarity_status_decision_date']);
        $input['rarity_status']['global'] = 'common';
        $this->post('taxa/1', $input)->assertStatus(302);
        $this->assertSame(date('Y-m-d'), $service->getForTaxon(1)['global']['rarity_status_decision_date']);
        $input['rarity_status']['global'] = '';
        $this->post('taxa/1', $input)->assertStatus(302);
        $row = $service->getForTaxon(1)['global'];
        $this->assertNull($row['rarity_status']);
        $this->assertNull($row['rarity_status_comment']);
        $this->assertNull($row['rarity_status_decision_date']);
    }

    /**
     * Reject malformed input, unknown or deleted regions, absent rationale and non-species.
     */
    public function testRarityValidationRejectsInvalidDecisionsWithoutWriting(): void
    {
        $db = db_connect();
        $db->table('taxa')->where('id', 1)->update(['species_id' => 1]);
        $regionId = $this->createTestRegion('TPG-RARITY-DELETED', 'Deleted rarity region');
        $db->table('geographic_regions')->where('id', $regionId)->update(['deleted_at' => '2026-01-01']);
        $this->authenticateAs('taxa-manager-rarity-invalid@example.com', 'manager');
        foreach ([
            ['rarity_status' => ['global' => 'rare']],
            ['rarity_status' => ['global' => 'invalid']],
            ['rarity_status' => ['global' => ['rare']]],
            ['rarity_status' => 'rare'],
            ['rarity_status' => ['999999' => 'rare'], 'rarity_status_comment' => ['999999' => 'Evidence']],
            ['rarity_status' => [$regionId => 'rare'], 'rarity_status_comment' => [$regionId => 'Evidence']],
            ['rarity_status' => [], 'rarity_status_comment' => ['global' => 'Orphan rationale']],
        ] as $input) {
            $this->post('taxa/1', $input)->assertStatus(302);
            $this->assertArrayHasKey('rarity_status', session('errors'));
            $this->assertSame(0, $db->table('taxon_stats')->countAllResults());
        }
        $db->table('taxa')->where('id', 1)->update(['species_id' => null]);
        $this->post('taxa/1', [
            'rarity_status' => ['global' => 'rare'], 'rarity_status_comment' => ['global' => 'Evidence'],
        ])->assertStatus(302);
        $this->assertStringContainsString('only be set for a species', session('errors')['rarity_status']);
        $this->assertSame(0, $db->table('taxon_stats')->countAllResults());
    }

    /**
     * Do not accept rarity decisions from a user without staff editing privileges.
     */
    public function testStandardUserCannotSaveRarityStatus(): void
    {
        db_connect()->table('taxa')->where('id', 1)->update(['species_id' => 1]);
        $this->authenticateAs('taxa-standard-rarity@example.com', 'user');
        $this->post('taxa/1', [
            'rarity_status' => ['global' => 'rare'], 'rarity_status_comment' => ['global' => 'Evidence'],
        ])->assertStatus(302);
        $this->assertSame(0, db_connect()->table('taxon_stats')->countAllResults());
    }

    public function testManagerCannotUpdateModerationFields(): void
    {
        $this->authenticateAs('taxa-manager-update@example.com', 'manager');

        $result = $this->post('taxa/1', [
            'rarity_group_name' => 'Terrestrial mammals',
            'taxon_remarks' => 'Manager note',
            'blocked' => '1',
            'blocked_reason' => 'Sensitive record',
        ]);

        $result->assertStatus(302);
        $result->assertRedirect();

        $taxon = model(TaxonModel::class)->find(1);

        $this->assertSame('Terrestrial mammals', $taxon['rarity_group_name']);
        $this->assertSame('Manager note', $taxon['taxon_remarks']);
        $this->assertSame(0, (int) $taxon['blocked']);
        $this->assertNull($taxon['blocked_reason']);
    }

    /**
     * Verify staff can reach the bulk media import page.
     */
    public function testStaffCanOpenBulkMediaImportPage(): void
    {
        $this->authenticateAs('taxa-media-import-manager@example.com', 'manager');

        $result = $this->get('taxon-media-imports');

        $result->assertStatus(200);
        $result->assertSee('Bulk taxon media import');
        $result->assertSee('Bulk media import');
        $this->assertStringContainsString('data-csrf-name="' . csrf_token() . '"', $result->getBody());
        $result->assertSee('photo-dropzone-selection');
        $result->assertSee('Upload files');
        $result->assertSee('Start import');
        $result->assertDontSee('Preflight and queue');
    }

    /**
     * Verify the bulk media import navigation link is staff-only.
     */
    public function testBulkMediaImportNavigationIsStaffOnly(): void
    {
        $this->authenticateAs('taxa-media-import-user@example.com', 'user');

        $result = $this->get('/');

        $result->assertStatus(200);
        $result->assertDontSee('Bulk media import');
    }

    public function testAdminCanUpdateBlockedFields(): void
    {
        $this->authenticateAs('taxa-admin@example.com', 'admin');

        $result = $this->post('taxa/1', [
            'blocked' => '1',
            'blocked_reason' => 'Sensitive record',
        ]);

        $result->assertStatus(302);
        $result->assertRedirect();

        $taxon = model(TaxonModel::class)->find(1);

        $this->assertSame(1, (int) $taxon['blocked']);
        $this->assertSame('Sensitive record', $taxon['blocked_reason']);
    }

    public function testAdminUnblockClearsReason(): void
    {
        $this->authenticateAs('taxa-admin-unblock@example.com', 'admin');

        $result = $this->post('taxa/2', [
            'blocked' => '0',
            'blocked_reason' => 'Should be cleared',
        ]);

        $result->assertStatus(302);
        $result->assertRedirect();

        $taxon = model(TaxonModel::class)->find(2);

        $this->assertSame(0, (int) $taxon['blocked']);
        $this->assertNull($taxon['blocked_reason']);
    }

    public function testUploadMediaRequiresFile(): void
    {
        $this->authenticateAs('taxa-manager-media-required@example.com', 'manager');

        $result = $this->post('taxa/1/media', [
            'alt_text' => 'No file test',
        ]);

        $result->assertStatus(302);
        $result->assertRedirect();
        $result->assertSessionHas('mediaErrors');
    }

    public function testStandardUserCannotUploadTaxonMedia(): void
    {
        $this->authenticateAs('taxa-standard-user@example.com', 'user');

        $result = $this->post('taxa/1/media', []);

        $result->assertStatus(302);
        $result->assertRedirect();

        $mediaCount = db_connect()->table('taxon_media')->where('taxon_id', 1)->countAllResults();

        $this->assertSame(0, $mediaCount);
    }

    public function testManagerCanUpdateExistingTaxonMediaMetadata(): void
    {
        $this->authenticateAs('taxa-manager-media-edit@example.com', 'manager');

        $uuid = '88888888-8888-4888-8888-888888888888';
        $now = date('Y-m-d H:i:s');

        db_connect()->table('taxon_media')->insert([
            'uuid' => $uuid,
            'taxon_id' => 1,
            'original_filename' => 'editable-photo.jpg',
            'storage_path' => '1/' . $uuid . '/original.jpg',
            'mime_type' => 'image/jpeg',
            'bytes' => 100,
            'width' => 100,
            'height' => 100,
            'alt_text' => 'Old alt',
            'caption' => 'Old caption',
            'attribution' => 'Old attribution',
            'license' => 'Old license',
            'sort_order' => 1,
            'is_primary' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $result = $this->post('taxa/1/media/update', [
            'media_uuid' => $uuid,
            'edit_alt_text' => 'New alt text',
            'edit_caption' => 'New caption',
            'edit_attribution' => 'New attribution',
            'edit_license' => 'CC BY 4.0',
            'edit_sort_order' => '5',
            'edit_is_primary' => '1',
        ]);

        $result->assertStatus(302);
        $result->assertRedirect();

        $row = db_connect()->table('taxon_media')
            ->where('uuid', $uuid)
            ->where('taxon_id', 1)
            ->get()
            ->getRowArray();

        $this->assertNotNull($row);
        $this->assertSame('New alt text', (string) $row['alt_text']);
        $this->assertSame('New caption', (string) $row['caption']);
        $this->assertSame('New attribution', (string) $row['attribution']);
        $this->assertSame('CC BY 4.0', (string) $row['license']);
        $this->assertSame(5, (int) $row['sort_order']);
        $this->assertSame(1, (int) $row['is_primary']);
    }

    public function testStandardUserCannotUpdateTaxonMediaMetadata(): void
    {
        $this->authenticateAs('taxa-standard-media-edit@example.com', 'user');

        $result = $this->post('taxa/1/media/update', [
            'media_uuid' => '11111111-1111-4111-8111-111111111111',
            'edit_alt_text' => 'Should not save',
        ]);

        $result->assertStatus(302);
        $result->assertRedirect();
    }

    private function authenticateAs(string $email, string $group): void
    {
        $this->actingAs($this->makeUser($email, $group));
        $this->withSession($_SESSION);
    }

    /**
     * Find or create a geographic region for admin UI feature tests.
     *
     * @param string $identifier Stable test identifier.
     * @param string $name Display name.
     * @return int Geographic region identifier.
     */
    private function createTestRegion(string $identifier, string $name): int
    {
        $db = db_connect();
        $source = $db->table('data_sources')->where('abbr', 'TPG')->get()->getRowArray();
        if ($source === null) {
            $db->table('data_sources')->insert([
                'abbr' => 'TPG',
                'title' => 'Taxa pages test source',
                'url' => 'https://example.test',
            ]);
            $sourceId = (int) $db->insertID();
        } else {
            $sourceId = (int) $source['id'];
        }

        $region = $db->table('geographic_regions')
            ->where('higher_geography_identifier', $identifier)
            ->get()
            ->getRowArray();
        if ($region !== null) {
            return (int) $region['id'];
        }

        $db->table('geographic_regions')->insert([
            'higher_geography_identifier' => $identifier,
            'higher_geography' => $name,
            'location_type' => 'Test region',
            'data_source_id' => $sourceId,
            'deleted_at' => null,
        ]);

        return (int) $db->insertID();
    }

    private function seedTaxa(): void
    {
        $db = db_connect();
        $now = date('Y-m-d H:i:s');

        $db->table('taxon_media_variants')->emptyTable();
        $db->table('taxon_media')->emptyTable();
        $db->table('geographic_regions_occurrences')->emptyTable();
        $db->table('occurrences')->emptyTable();
        $db->table('taxon_stats')->emptyTable();
        $db->table('taxon_year_stats')->emptyTable();
        $db->table('taxon_names')->emptyTable();
        $db->table('taxa')->emptyTable();
        $db->table('taxon_groups')->emptyTable();
        $db->table('taxon_ranks')->emptyTable();
        $db->table('recording_schemes')->emptyTable();

        $db->table('taxon_groups')->insert([
            'id' => 1,
            'title' => 'Bees',
            'friendly' => 'Bee species',
            'external_key' => 'bees',
            'indicia_taxon_group_id' => 10,
            'implied' => 0,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $db->table('taxon_ranks')->insert([
            'id' => 1,
            'rank' => 'Species',
            'abbr' => 'sp',
            'sort_order' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $db->table('recording_schemes')->insert([
            'id' => 1,
            'external_key' => 'SCHEME-0001',
            'title' => 'Alpha scheme',
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ]);

        $db->table('taxa')->insertBatch([
            [
                'id' => 1,
                'taxon_identifier' => 'NHMSYS0021054498',
                'scientific_name_identifier' => 'TVK-001',
                'scientific_name' => 'Bombus terrestris',
                'scientific_name_authorship' => 'Linnaeus, 1758',
                'vernacular_name' => 'Buff-tailed Bumblebee',
                'taxon_rank_id' => 1,
                'order_id' => null,
                'superfamily_id' => null,
                'family_id' => null,
                'genus_id' => null,
                'species_id' => null,
                'taxon_group_id' => 1,
                'id_difficulty' => 2,
                'recording_scheme_id' => 1,
                'conservation_status' => 'LC',
                'taxon_remarks' => null,
                'rarity_group_name' => 'Bees, Wasps and Ants',
                'rarity_category' => 4,
                'blocked' => 0,
                'blocked_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
            [
                'id' => 2,
                'taxon_identifier' => 'NHMSYS0021054499',
                'scientific_name_identifier' => 'TVK-002',
                'scientific_name' => 'Bombus lucorum',
                'scientific_name_authorship' => null,
                'vernacular_name' => 'White-tailed Bumblebee',
                'taxon_rank_id' => 1,
                'order_id' => null,
                'superfamily_id' => null,
                'family_id' => null,
                'genus_id' => null,
                'species_id' => null,
                'taxon_group_id' => 1,
                'id_difficulty' => 2,
                'recording_scheme_id' => 1,
                'conservation_status' => 'LC',
                'taxon_remarks' => null,
                'rarity_group_name' => 'Bees, Wasps and Ants',
                'rarity_category' => 4,
                'blocked' => 1,
                'blocked_reason' => 'Existing reason',
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
        ]);
    }


    private function makeUser(string $email, string $group)
    {
        /** @var UserModel $users */
        $users = model(setting('Auth.userProvider'));

        $user = $users->createNewUser([
            'username' => strstr($email, '@', true),
            'email' => $email,
            'password' => 'Password123!',
        ]);

        $users->save($user);

        $saved = $users->findById($users->getInsertID());
        $saved->activate();
        $users->save($saved);

        if ($group !== 'user') {
            $saved->addGroup($group);
        }

        return $saved;
    }
}
