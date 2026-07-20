<?php

namespace Tests;

use App\Models\DataSourceModel;
use App\Models\OccurrenceModel;
use Config\Auth;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class DataSourcesOccurrencesPagesTest extends CIUnitTestCase
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

        $this->seedFixtures();
    }

    public function testListPagesRequireLogin(): void
    {
        foreach (['data-sources', 'occurrences'] as $path) {
            $result = $this->get($path);
            $result->assertStatus(302);
            $result->assertRedirect();
        }
    }

    public function testManagerCanAccessDataSourcesAndOccurrences(): void
    {
        $this->authenticateAs('staff-manager@example.com', 'manager');

        $dataSources = $this->get('data-sources');
        $dataSources->assertStatus(200);
        $dataSources->assertSee('Data sources');
        $dataSources->assertSee('Alpha source');

        $occurrences = $this->get('occurrences');
        $occurrences->assertStatus(200);
        $occurrences->assertSee('Occurrences');
        $occurrences->assertSee('NBN:123456789');
    }

    public function testAdminCanCreateAndUpdateDataSource(): void
    {
        $this->authenticateAs('data-sources-admin@example.com', 'admin');

        $create = $this->post('data-sources/create', [
            'abbr' => 'GBIF',
            'title' => 'GBIF source',
            'url' => 'https://gbif.example.com',
        ]);

        $create->assertStatus(302);
        $create->assertRedirect();

        $created = model(DataSourceModel::class)->where('abbr', 'GBIF')->first();
        $this->assertIsArray($created);
        $this->assertSame('GBIF source', (string) $created['title']);

        $update = $this->post('data-sources/' . $created['id'], [
            'abbr' => 'GBIF',
            'title' => 'GBIF source updated',
            'url' => 'https://gbif-updated.example.com',
        ]);

        $update->assertStatus(302);
        $update->assertRedirect();

        $updated = model(DataSourceModel::class)->find($created['id']);
        $this->assertIsArray($updated);
        $this->assertSame('GBIF source updated', (string) $updated['title']);
    }

    public function testOccurrencesListAppliesFilters(): void
    {
        $this->authenticateAs('occurrence-manager-filter@example.com', 'manager');

        $result = $this->get('occurrences?blocked=1');

        $result->assertStatus(200);
        $result->assertSee('NBN:BLOCKED001');
        $result->assertDontSee('NBN:123456789');
    }

    public function testManagerCanUpdateOccurrenceModeration(): void
    {
        $this->authenticateAs('occurrence-manager-update@example.com', 'manager');

        $result = $this->post('occurrences/1', [
            'blocked' => '1',
            'blocked_reason' => 'Sensitive site',
        ]);

        $result->assertStatus(302);
        $result->assertRedirect();

        $occurrence = model(OccurrenceModel::class)->find(1);
        $this->assertIsArray($occurrence);
        $this->assertSame(1, (int) $occurrence['blocked']);
        $this->assertSame('Sensitive site', (string) $occurrence['blocked_reason']);
    }

    public function testBlockedOccurrenceRequiresReason(): void
    {
        $this->authenticateAs('occurrence-manager-validation@example.com', 'manager');

        $result = $this->post('occurrences/1', [
            'blocked' => '1',
            'blocked_reason' => '',
        ]);

        $result->assertStatus(302);
        $result->assertRedirect();
        $result->assertSessionHas('errors');
    }

    /**
     * Authenticate as a user and preserve session for feature requests.
     *
     * @param string $email User email.
     * @param string $group Group to assign.
     * @return void
     */
    private function authenticateAs(string $email, string $group): void
    {
        $this->actingAs($this->makeUser($email, $group));
        $this->withSession($_SESSION);
    }

    /**
     * Seed the minimal fixture set for data source and occurrence pages.
     *
     * @return void
     */
    private function seedFixtures(): void
    {
        $db = db_connect();
        $now = date('Y-m-d H:i:s');

        $db->table('occurrences')->emptyTable();
        $db->table('taxon_names')->emptyTable();
        $db->table('taxa')->emptyTable();
        $db->table('taxon_groups')->emptyTable();
        $db->table('taxon_ranks')->emptyTable();
        $db->table('recording_schemes')->emptyTable();
        $db->table('data_sources')->emptyTable();

        $db->table('data_sources')->insertBatch([
            [
                'id' => 1,
                'abbr' => 'NBN',
                'title' => 'Alpha source',
                'url' => 'https://alpha.example.com',
            ],
            [
                'id' => 2,
                'abbr' => 'IRC',
                'title' => 'Beta source',
                'url' => 'https://beta.example.com',
            ],
        ]);

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

        $db->table('taxa')->insert([
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
                'data_source_id' => 2,
                'blocked' => 1,
                'blocked_reason' => 'Sensitive occurrence',
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ],
        ]);
    }

    /**
     * Create and return an activated user assigned to the requested role.
     *
     * @param string $email User email.
     * @param string $group Group to assign.
     * @return object
     */
    private function makeUser(string $email, string $group)
    {
        /** @var UserModel $users */
        $users = model(setting('Auth.userProvider'));

        $user = $users->createNewUser([
            'username' => (string) strstr($email, '@', true),
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
