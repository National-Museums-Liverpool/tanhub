<?php

namespace Tests;

use Config\Auth;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class AdminReferenceTablesTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    /**
     * Prepare database fixtures for each test.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Isolate this test class from shared service singletons mutated by earlier tests.
        \Config\Services::reset();

        // Ensure starting point is a guest: clear session and any persisted auth cookie state.
        $_SESSION = [];
        $_COOKIE = [];
        $this->withSession([]);

        // Feature tests can leak in-memory auth state across classes in a single PHPUnit process.
        if (function_exists('auth')) {
            try {
                auth()->logout();
            } catch (\Throwable) {
                // Ignore; some test contexts may not have a fully booted auth service.
            }
        }

        config(Auth::class)->actions['register'] = null;

        $migrate = service('migrations');
        $migrate->setNamespace(null);
        $migrate->latest();

        $this->seedReferenceData();
    }

    /**
     * Ensure unauthenticated users are redirected away from protected list pages.
     */
    public function testListPagesRequireLogin(): void
    {
        foreach (['taxon-groups', 'taxon-ranks', 'taxa', 'geographic-regions', 'recording-schemes', 'data-sources', 'occurrences'] as $path) {
            $result = $this->get($path);
            $result->assertStatus(302);
            $result->assertRedirect();
        }
    }

    /**
     * Ensure standard users cannot access protected list pages.
     */
    public function testListPagesDenyGeneralUserRole(): void
    {
        $this->authenticateAs('general-user@example.com', 'user');

        foreach (['taxon-groups', 'taxon-ranks', 'taxa', 'geographic-regions', 'recording-schemes', 'data-sources', 'occurrences'] as $path) {
            $result = $this->get($path);
            $result->assertStatus(302);
            $result->assertRedirect();
        }
    }

    /**
     * Ensure managers can access all list pages.
     */
    public function testListPagesAllowManagerRole(): void
    {
        $this->authenticateAs('manager-lists@example.com', 'manager');

        $taxonGroups = $this->get('taxon-groups');
        $taxonGroups->assertStatus(200);
        $taxonGroups->assertSee('Taxon groups');

        $taxonRanks = $this->get('taxon-ranks');
        $taxonRanks->assertStatus(200);
        $taxonRanks->assertSee('Taxon ranks');

        $taxa = $this->get('taxa');
        $taxa->assertStatus(200);
        $taxa->assertSee('Taxa');

        $geographicRegions = $this->get('geographic-regions');
        $geographicRegions->assertStatus(200);
        $geographicRegions->assertSee('Geographic regions');

        $recordingSchemes = $this->get('recording-schemes');
        $recordingSchemes->assertStatus(200);
        $recordingSchemes->assertSee('Recording schemes');

        $dataSources = $this->get('data-sources');
        $dataSources->assertStatus(200);
        $dataSources->assertSee('Data sources');

        $occurrences = $this->get('occurrences');
        $occurrences->assertStatus(200);
        $occurrences->assertSee('Occurrences');
    }

    /**
     * Validate taxon ranks list columns and default sort behavior.
     */
    public function testTaxonRanksListMatchesSpecification(): void
    {
        $this->authenticateAs('manager-super@example.com', 'manager');

        $result = $this->get('taxon-ranks');

        $result->assertStatus(200);
        $result->assertSee('Rank');
        $result->assertSee('Abbreviation');
        $result->assertSee('Sort order');
        $result->assertSee('Details');

        $body = (string) $result->response()->getBody();
        $alphaPos = strpos($body, 'Family');
        $betaPos = strpos($body, 'Species');

        $this->assertIsInt($alphaPos);
        $this->assertIsInt($betaPos);
        $this->assertLessThan($betaPos, $alphaPos);
    }

    /**
     * Validate geographic regions list columns and default sort behavior.
     */
    public function testGeographicRegionsMatchesSpecification(): void
    {
        $this->authenticateAs('manager-families@example.com', 'manager');

        $result = $this->get('geographic-regions');

        $result->assertStatus(200);
        $result->assertSee('Identifier');
        $result->assertSee('Region');
        $result->assertSee('Location type');
        $result->assertSee('Occurrences');
    }

    /**
     * Validate recording schemes list columns and default sort behavior.
     */
    public function testRecordingSchemesListMatchesSpecification(): void
    {
        $this->authenticateAs('manager-schemes@example.com', 'manager');

        $result = $this->get('recording-schemes');

        $result->assertStatus(200);
        $result->assertSee('External key');
        $result->assertSee('Title');
        $result->assertSee('Taxa count');
        $result->assertSee('View');

        $body = (string) $result->response()->getBody();
        $alphaPos = strpos($body, 'Alpha scheme');
        $betaPos = strpos($body, 'Beta scheme');

        $this->assertIsInt($alphaPos);
        $this->assertIsInt($betaPos);
        $this->assertLessThan($betaPos, $alphaPos);
    }

    /**
     * Validate list-page q search on key reference tables.
     */
    public function testListPagesApplySearchQuery(): void
    {
        $this->authenticateAs('manager-search@example.com', 'manager');

        $ranks = $this->get('taxon-ranks?q=fam');
        $ranks->assertStatus(200);
        $ranks->assertSee('Family');
        $ranks->assertDontSee('Species');

        $schemes = $this->get('recording-schemes?q=beta');
        $schemes->assertStatus(200);
        $schemes->assertSee('Beta scheme');
        $schemes->assertDontSee('Alpha scheme');
    }

    /**
     * Validate the taxon rank details page is read-only.
     */
    public function testTaxonRankDetailShowsReadOnlyFields(): void
    {
        $this->authenticateAs('manager-rank-detail@example.com', 'manager');

        $result = $this->get('taxon-ranks/2');

        $result->assertStatus(200);
        $result->assertSee('Read-only');
        $result->assertSee('Rank');
        $result->assertSee('Abbreviation');
        $this->assertStringContainsString('value="Family"', (string) $result->response()->getBody());
        $this->assertStringContainsString('value="fam"', (string) $result->response()->getBody());
        $result->assertSee('Back to list');
    }

    /**
     * Validate read-only recording scheme detail and related taxa count.
     */
    public function testRecordingSchemeDetailShowsReadOnlyFieldsAndTaxaCount(): void
    {
        $this->authenticateAs('manager-scheme-detail@example.com', 'manager');

        $result = $this->get('recording-schemes/1');

        $result->assertStatus(200);
        $result->assertSee('Read-only');
        $result->assertSee('Taxa count');
        $this->assertStringContainsString('value="2"', (string) $result->response()->getBody());
        $result->assertSee('Back to list');
    }

    /**
     * Ensure unknown detail pages return 404.
     */
    public function testDetailPagesReturnNotFoundForUnknownId(): void
    {
        $this->authenticateAs('manager-missing@example.com', 'manager');

        foreach (['taxon-ranks/9999', 'recording-schemes/9999'] as $path) {
            try {
                $this->get($path);
                $this->fail('Expected PageNotFoundException for path: ' . $path);
            } catch (PageNotFoundException $exception) {
                $this->assertInstanceOf(PageNotFoundException::class, $exception);
            }
        }
    }

    /**
     * Authenticate as a user and preserve session for feature requests.
     */
    private function authenticateAs(string $email, string $group): void
    {
        $this->actingAs($this->makeUser($email, $group));
        $this->withSession($_SESSION);
    }

    /**
     * Seed required reference rows and taxa rows for list/detail tests.
     */
    private function seedReferenceData(): void
    {
        $db = db_connect();

        $db->table('taxa')->emptyTable();
        $db->table('taxon_groups')->emptyTable();
        $db->table('taxon_ranks')->emptyTable();
        $db->table('recording_schemes')->emptyTable();

        $db->table('taxon_groups')->insert([
            'id' => 1,
            'title' => 'Insecta',
            'friendly' => 'Insects',
            'external_key' => 'TANHUB-TG-1',
            'indicia_taxon_group_id' => 1,
            'implied' => 1,
        ]);

        $db->table('taxon_groups')->insert([
            'id' => 2,
            'title' => 'Marine mammals',
            'friendly' => 'Whales & dolphins',
            'external_key' => 'TANHUB-TG-2',
            'indicia_taxon_group_id' => 2,
            'implied' => 0,
        ]);

        $db->table('taxon_ranks')->insert([
            'id' => 1,
            'rank' => 'Order',
            'abbr' => 'ord',
            'sort_order' => 1,
        ]);

        $db->table('taxon_ranks')->insert([
            'id' => 2,
            'rank' => 'Family',
            'abbr' => 'fam',
            'sort_order' => 2,
        ]);

        $db->table('taxon_ranks')->insert([
            'id' => 3,
            'rank' => 'Species',
            'abbr' => 'sp',
            'sort_order' => 3,
        ]);

        $db->table('recording_schemes')->insert([
            'id' => 1,
            'external_key' => 'SCHEME-0000000001',
            'title' => 'Alpha scheme',
        ]);

        $db->table('recording_schemes')->insert([
            'id' => 2,
            'external_key' => 'SCHEME-0000000002',
            'title' => 'Beta scheme',
        ]);

        $db->table('taxa')->insertBatch([
            [
                'id' => 1,
                'taxon_identifier' => 'TAX-ONE',
                'scientific_name_identifier' => 'TAX-SCI-ONE',
                'scientific_name' => 'Taxon one',
                'scientific_name_authorship' => null,
                'vernacular_name' => 'Taxon One',
                'taxon_rank_id' => 1,
                'order_id' => 1,
                'superfamily_id' => null,
                'family_id' => null,
                'genus_id' => null,
                'species_id' => null,
                'taxon_group_id' => 1,
                'id_difficulty' => null,
                'recording_scheme_id' => 2,
                'conservation_status' => null,
                'taxon_remarks' => null,
                'rarity_group_name' => 'Common',
                'blocked' => 0,
                'blocked_reason' => null,
            ],
            [
                'id' => 2,
                'taxon_identifier' => 'TAX-TWO',
                'scientific_name_identifier' => 'TAX-SCI-TWO',
                'scientific_name' => 'Taxon two',
                'scientific_name_authorship' => null,
                'vernacular_name' => 'Taxon Two',
                'taxon_rank_id' => 2,
                'order_id' => 1,
                'superfamily_id' => 2,
                'family_id' => null,
                'genus_id' => null,
                'species_id' => null,
                'taxon_group_id' => 1,
                'id_difficulty' => null,
                'recording_scheme_id' => 1,
                'conservation_status' => null,
                'taxon_remarks' => null,
                'rarity_group_name' => 'Common',
                'blocked' => 0,
                'blocked_reason' => null,
            ],
            [
                'id' => 3,
                'taxon_identifier' => 'TAX-THREE',
                'scientific_name_identifier' => 'TAX-SCI-THREE',
                'scientific_name' => 'Taxon three',
                'scientific_name_authorship' => null,
                'vernacular_name' => 'Taxon Three',
                'taxon_rank_id' => 3,
                'order_id' => 1,
                'superfamily_id' => 2,
                'family_id' => 3,
                'genus_id' => null,
                'species_id' => 3,
                'taxon_group_id' => 1,
                'id_difficulty' => null,
                'recording_scheme_id' => 1,
                'conservation_status' => null,
                'taxon_remarks' => null,
                'rarity_group_name' => 'Common',
                'blocked' => 0,
                'blocked_reason' => null,
            ],
        ]);
    }

    /**
     * Create and return an activated user assigned to the requested role.
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
