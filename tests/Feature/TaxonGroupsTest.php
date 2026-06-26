<?php

namespace Tests;

use App\Models\TaxonGroupModel;
use Config\Auth;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class TaxonGroupsTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable registration action during tests to avoid activation flow redirects.
        config(Auth::class)->actions['register'] = null;

        $migrate = service('migrations');
        $migrate->setNamespace(null);
        $migrate->latest();

        $this->seedTaxonGroups();
    }

    public function testListRequiresLogin(): void
    {
        $result = $this->get('taxon-groups');

        $result->assertStatus(302);
        $result->assertRedirect();
    }

    public function testListDeniesGeneralUserRole(): void
    {
        $this->authenticateAs('user@example.com', 'user');

        $result = $this->get('taxon-groups');

        $result->assertStatus(302);
        $result->assertRedirect();
    }

    public function testListAllowsManagerRole(): void
    {
        $this->authenticateAs('manager@example.com', 'manager');

        $result = $this->get('taxon-groups');

        $result->assertStatus(200);
        $result->assertSee('Taxon groups');
    }

    public function testListShowsSpecifiedColumns(): void
    {
        $this->authenticateAs('admin1@example.com', 'admin');

        $result = $this->get('taxon-groups');

        $result->assertStatus(200);
        $result->assertSee('ID');
        $result->assertSee('Title');
        $result->assertSee('Friendly');
        $result->assertSee('External Key');
        $result->assertSee('Edit');
    }

    public function testListSortsByTitleAscByDefault(): void
    {
        $this->authenticateAs('admin2@example.com', 'admin');

        $result = $this->get('taxon-groups');

        $result->assertStatus(200);
        $result->assertSee('Aves');
        $result->assertSee('Insecta');
        $result->assertSee('Mammals');
    }

    public function testEditRequiresManagerOrAdmin(): void
    {
        $this->authenticateAs('user2@example.com', 'user');

        $result = $this->get('taxon-groups/1/edit');

        $result->assertStatus(302);
        $result->assertRedirect();
    }

    public function testEditShowsReadOnlyAndEditableFields(): void
    {
        $this->authenticateAs('manager2@example.com', 'manager');

        $result = $this->get('taxon-groups/1/edit');

        $result->assertStatus(200);
        $result->assertSee('Read-only');
        $result->assertSee('External Key');
        $result->assertSeeInField('friendly', 'Insects');
    }

    public function testUpdateOnlyChangesFriendlyField(): void
    {
        $this->authenticateAs('admin3@example.com', 'admin');

        $before = model(TaxonGroupModel::class)->find(1);

        $result = $this->post('taxon-groups/1/edit', [
            'friendly' => 'Invertebrates',
        ]);

        $result->assertStatus(302);
        $result->assertRedirect();

        $after = model(TaxonGroupModel::class)->find(1);

        $this->assertSame('Invertebrates', $after['friendly']);
        $this->assertSame($before['title'], $after['title']);
        $this->assertSame($before['external_key'], $after['external_key']);
    }

    public function testUpdateValidatesFriendlyMaxLength(): void
    {
        $this->authenticateAs('admin4@example.com', 'admin');

        $result = $this->post('taxon-groups/1/edit', [
            'friendly' => str_repeat('x', 201),
        ]);

        $result->assertStatus(302);
        $result->assertRedirect();
        $result->assertSessionHas('errors');
    }

    public function testUpdateAllowsEmptyFriendly(): void
    {
        $this->authenticateAs('manager3@example.com', 'manager');

        $result = $this->post('taxon-groups/1/edit', [
            'friendly' => '',
        ]);

        $result->assertStatus(302);

        $updated = model(TaxonGroupModel::class)->find(1);
        $this->assertNull($updated['friendly']);
    }

    private function authenticateAs(string $email, string $group): void
    {
        $this->actingAs($this->makeUser($email, $group));
        // FeatureTestTrait resets $_SESSION from $this->session during requests.
        $this->withSession($_SESSION);
    }

    private function seedTaxonGroups(): void
    {
        $model = model(TaxonGroupModel::class);

        $model->db->table('taxon_groups')->truncate();
        $now = date('Y-m-d H:i:s');

        $model->db->table('taxon_groups')->insertBatch([
            [
                'id' => 1,
                'title' => 'Insecta',
                'friendly' => 'Insects',
                'external_key' => 'TANHUB0000000001',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 2,
                'title' => 'Mammals',
                'friendly' => null,
                'external_key' => 'TANHUB0000000002',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => 3,
                'title' => 'Aves',
                'friendly' => 'Birds',
                'external_key' => 'TANHUB0000000003',
                'created_at' => $now,
                'updated_at' => $now,
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
