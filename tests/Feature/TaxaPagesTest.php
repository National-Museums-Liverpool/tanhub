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
            'rarity_group_name' => 'locally-rare',
            'taxon_remarks' => 'Manager edited remarks',
        ]);

        $result->assertStatus(302);
        $result->assertRedirect();

        $taxon = model(TaxonModel::class)->find(1);

        $this->assertSame('locally-rare', $taxon['rarity_group_name']);
        $this->assertSame('Manager edited remarks', $taxon['taxon_remarks']);
    }

    public function testManagerCannotUpdateModerationFields(): void
    {
        $this->authenticateAs('taxa-manager-update@example.com', 'manager');

        $result = $this->post('taxa/1', [
            'rarity_group_name' => 'locally-common',
            'taxon_remarks' => 'Manager note',
            'blocked' => '1',
            'blocked_reason' => 'Sensitive record',
        ]);

        $result->assertStatus(302);
        $result->assertRedirect();

        $taxon = model(TaxonModel::class)->find(1);

        $this->assertSame('locally-common', $taxon['rarity_group_name']);
        $this->assertSame('Manager note', $taxon['taxon_remarks']);
        $this->assertSame(0, (int) $taxon['blocked']);
        $this->assertNull($taxon['blocked_reason']);
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
                'rarity_group_name' => 'common',
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
                'rarity_group_name' => 'common',
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
