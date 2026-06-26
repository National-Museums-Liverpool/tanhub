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
        foreach (['orders', 'families', 'superfamilies', 'recording-schemes'] as $path) {
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

        foreach (['orders', 'families', 'superfamilies', 'recording-schemes'] as $path) {
            $result = $this->get($path);
            $result->assertStatus(302);
            $result->assertRedirect();
        }
    }

    /**
     * Ensure managers can access all three list pages.
     */
    public function testListPagesAllowManagerRole(): void
    {
        $this->authenticateAs('manager-lists@example.com', 'manager');

        $orders = $this->get('orders');
        $orders->assertStatus(200);
        $orders->assertSee('Orders');

        $families = $this->get('families');
        $families->assertStatus(200);
        $families->assertSee('Families');

        $superfamilies = $this->get('superfamilies');
        $superfamilies->assertStatus(200);
        $superfamilies->assertSee('Superfamilies');

        $recordingSchemes = $this->get('recording-schemes');
        $recordingSchemes->assertStatus(200);
        $recordingSchemes->assertSee('Recording schemes');
    }

    /**
     * Validate orders list columns and default sort behavior.
     */
    public function testOrdersListMatchesSpecification(): void
    {
        $this->authenticateAs('manager-orders@example.com', 'manager');

        $result = $this->get('orders');

        $result->assertStatus(200);
        $result->assertSee('Taxon identifier');
        $result->assertSee('Scientific name');
        $result->assertSee('Vernacular name');
        $result->assertSee('Taxa count');
        $result->assertSee('View');

        $body = (string) $result->response()->getBody();
        $alphaPos = strpos($body, 'Alpha order');
        $betaPos = strpos($body, 'Beta order');

        $this->assertIsInt($alphaPos);
        $this->assertIsInt($betaPos);
        $this->assertLessThan($betaPos, $alphaPos);
    }

    /**
     * Validate superfamilies list columns and default sort behavior.
     */
    public function testSuperfamiliesListMatchesSpecification(): void
    {
        $this->authenticateAs('manager-super@example.com', 'manager');

        $result = $this->get('superfamilies');

        $result->assertStatus(200);
        $result->assertSee('Taxon identifier');
        $result->assertSee('Scientific name');
        $result->assertSee('Vernacular name');
        $result->assertSee('Taxa count');
        $result->assertSee('View');

        $body = (string) $result->response()->getBody();
        $alphaPos = strpos($body, 'Alpha superfamily');
        $betaPos = strpos($body, 'Beta superfamily');

        $this->assertIsInt($alphaPos);
        $this->assertIsInt($betaPos);
        $this->assertLessThan($betaPos, $alphaPos);
    }

    /**
     * Validate families list columns and default sort behavior.
     */
    public function testFamiliesListMatchesSpecification(): void
    {
        $this->authenticateAs('manager-families@example.com', 'manager');

        $result = $this->get('families');

        $result->assertStatus(200);
        $result->assertSee('Taxon identifier');
        $result->assertSee('Scientific name');
        $result->assertSee('Vernacular name');
        $result->assertSee('Taxa count');
        $result->assertSee('View');

        $body = (string) $result->response()->getBody();
        $alphaPos = strpos($body, 'Alpha family');
        $betaPos = strpos($body, 'Beta family');

        $this->assertIsInt($alphaPos);
        $this->assertIsInt($betaPos);
        $this->assertLessThan($betaPos, $alphaPos);
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
     * Validate read-only order detail and related taxa count.
     */
    public function testOrderDetailShowsReadOnlyFieldsAndTaxaCount(): void
    {
        $this->authenticateAs('manager-order-detail@example.com', 'manager');

        $result = $this->get('orders/1');

        $result->assertStatus(200);
        $result->assertSee('Read-only');
        $result->assertSee('Taxa count');
        $this->assertStringContainsString('value="2"', (string) $result->response()->getBody());
        $result->assertSee('Back to list');
    }

    /**
     * Validate read-only superfamily detail and related taxa count.
     */
    public function testSuperfamilyDetailShowsReadOnlyFieldsAndTaxaCount(): void
    {
        $this->authenticateAs('manager-super-detail@example.com', 'manager');

        $result = $this->get('superfamilies/1');

        $result->assertStatus(200);
        $result->assertSee('Read-only');
        $result->assertSee('Taxa count');
        $this->assertStringContainsString('value="2"', (string) $result->response()->getBody());
        $result->assertSee('Back to list');
    }

    /**
     * Validate read-only family detail and related taxa count.
     */
    public function testFamilyDetailShowsReadOnlyFieldsAndTaxaCount(): void
    {
        $this->authenticateAs('manager-family-detail@example.com', 'manager');

        $result = $this->get('families/1');

        $result->assertStatus(200);
        $result->assertSee('Read-only');
        $result->assertSee('Taxa count');
        $this->assertStringContainsString('value="2"', (string) $result->response()->getBody());
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

        foreach (['orders/9999', 'families/9999', 'superfamilies/9999', 'recording-schemes/9999'] as $path) {
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
        $db->table('orders')->emptyTable();
        $db->table('superfamilies')->emptyTable();
        $db->table('families')->emptyTable();
        $db->table('taxon_groups')->emptyTable();
        $db->table('recording_schemes')->emptyTable();

        $db->table('taxon_groups')->insert([
            'id' => 1,
            'title' => 'Insecta',
            'friendly' => 'Insects',
            'external_key' => 'TANHUB-TG-1',
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

        $db->table('orders')->insertBatch([
            [
                'id' => 1,
                'taxon_identifier' => 'ORD-ALPHA',
                'scientific_name_identifier' => 'ORD-SCI-ALPHA',
                'scientific_name' => 'Alpha order',
                'scientific_name_authorship' => 'Auth A',
                'vernacular_name' => 'Order Alpha',
            ],
            [
                'id' => 2,
                'taxon_identifier' => 'ORD-BETA',
                'scientific_name_identifier' => 'ORD-SCI-BETA',
                'scientific_name' => 'Beta order',
                'scientific_name_authorship' => 'Auth B',
                'vernacular_name' => 'Order Beta',
            ],
        ]);

        $db->table('superfamilies')->insertBatch([
            [
                'id' => 1,
                'taxon_identifier' => 'SUP-ALPHA',
                'scientific_name_identifier' => 'SUP-SCI-ALPHA',
                'scientific_name' => 'Alpha superfamily',
                'scientific_name_authorship' => 'Auth A',
                'vernacular_name' => 'Superfamily Alpha',
            ],
            [
                'id' => 2,
                'taxon_identifier' => 'SUP-BETA',
                'scientific_name_identifier' => 'SUP-SCI-BETA',
                'scientific_name' => 'Beta superfamily',
                'scientific_name_authorship' => 'Auth B',
                'vernacular_name' => 'Superfamily Beta',
            ],
        ]);

        $db->table('families')->insertBatch([
            [
                'id' => 1,
                'taxon_identifier' => 'FAM-ALPHA',
                'scientific_name_identifier' => 'FAM-SCI-ALPHA',
                'scientific_name' => 'Alpha family',
                'scientific_name_authorship' => 'Auth A',
                'vernacular_name' => 'Family Alpha',
            ],
            [
                'id' => 2,
                'taxon_identifier' => 'FAM-BETA',
                'scientific_name_identifier' => 'FAM-SCI-BETA',
                'scientific_name' => 'Beta family',
                'scientific_name_authorship' => 'Auth B',
                'vernacular_name' => 'Family Beta',
            ],
        ]);

        $db->table('taxa')->insertBatch([
            [
                'id' => 1,
                'taxon_identifier' => 'TAX-ONE',
                'scientific_name_identifier' => 'TAX-SCI-ONE',
                'scientific_name' => 'Taxon one',
                'scientific_name_authorship' => null,
                'vernacular_name' => 'Taxon One',
                'order_id' => 1,
                'superfamily_id' => 1,
                'family_id' => 1,
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
                'order_id' => 1,
                'superfamily_id' => 1,
                'family_id' => 1,
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
                'order_id' => 2,
                'superfamily_id' => 2,
                'family_id' => 2,
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
