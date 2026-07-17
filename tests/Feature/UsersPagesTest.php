<?php

namespace Tests;

use Config\Auth;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class UsersPagesTest extends CIUnitTestCase
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
    }

    public function testUsersPagesRequireAdminLogin(): void
    {
        $list = $this->get('users');
        $list->assertStatus(302);
        $list->assertRedirect();

        $create = $this->get('users/create');
        $create->assertStatus(302);
        $create->assertRedirect();

        $store = $this->post('users/create', [
            'username' => 'new-user',
            'email' => 'new-user@example.com',
            'active' => '1',
            'groups' => ['user'],
            'password' => 'Password123!',
            'password_confirm' => 'Password123!',
        ]);
        $store->assertStatus(302);
        $store->assertRedirect();
    }

    public function testManagerCannotAccessUsersPages(): void
    {
        $this->authenticateAs('manager-users@example.com', 'manager');

        $result = $this->get('users');
        $result->assertStatus(302);
        $result->assertRedirect();
    }

    public function testPublicRegistrationPageIsDisabled(): void
    {
        $result = $this->get('register');

        $this->assertNotSame(200, $result->response()->getStatusCode());
    }

    public function testAdminCanListUsers(): void
    {
        $this->createManagedUser('managed-list@example.com', 'managed-list', true);
        $this->authenticateAs('admin-list@example.com', 'admin');

        $result = $this->get('users');

        $result->assertStatus(200);
        $result->assertSee('Users');
        $result->assertSee('managed-list@example.com');
    }

    public function testAdminCanCreateBlockedUser(): void
    {
        $this->authenticateAs('admin-create@example.com', 'admin');

        $result = $this->post('users/create', [
            'username' => 'new-blocked',
            'email' => 'new-blocked@example.com',
            'active' => '0',
            'groups' => ['manager'],
            'password' => 'Password123!',
            'password_confirm' => 'Password123!',
        ]);

        $result->assertStatus(302);
        $result->assertRedirect();

        $user = $this->userModel()->findByCredentials(['email' => 'new-blocked@example.com']);

        $this->assertNotNull($user);
        $this->assertSame('new-blocked', (string) $user->username);
        $this->assertFalse((bool) $user->active);
        $userWithGroups = $this->userModel()->withGroups()->findById((int) $user->id);
        $this->assertNotNull($userWithGroups);
        $this->assertTrue($userWithGroups->inGroup('manager'));
        $this->assertFalse($userWithGroups->inGroup('user'));
    }

    public function testAdminCanUpdateUserAndPassword(): void
    {
        $target = $this->createManagedUser('target-user@example.com', 'target-user', true);
        $this->authenticateAs('admin-update@example.com', 'admin');

        $before = $this->userModel()->findByCredentials(['email' => 'target-user@example.com']);

        $this->assertNotNull($before);

        $result = $this->post('users/' . $target->id, [
            'username' => 'target-user-updated',
            'email' => 'target-user-updated@example.com',
            'active' => '0',
            'groups' => ['manager', 'user'],
            'password' => 'NewPass123!',
            'password_confirm' => 'NewPass123!',
        ]);

        $result->assertStatus(302);
        $result->assertRedirect();

        $updated = $this->userModel()->findByCredentials(['email' => 'target-user-updated@example.com']);

        $this->assertNotNull($updated);
        $this->assertSame('target-user-updated', (string) $updated->username);
        $this->assertFalse((bool) $updated->active);
        $this->assertNotSame((string) $before->password_hash, (string) $updated->password_hash);
        $this->assertTrue(service('passwords')->verify('NewPass123!', (string) $updated->password_hash));

        $updatedWithGroups = $this->userModel()->withGroups()->findById($target->id);

        $this->assertNotNull($updatedWithGroups);
        $this->assertTrue($updatedWithGroups->inGroup('manager'));
        $this->assertTrue($updatedWithGroups->inGroup('user'));
        $this->assertFalse($updatedWithGroups->inGroup('admin'));
    }

    private function authenticateAs(string $email, string $group): void
    {
        $this->actingAs($this->makeUser($email, $group));
        $this->withSession($_SESSION);
    }

    private function createManagedUser(string $email, string $username, bool $active)
    {
        $users = $this->userModel();
        $user = $users->createNewUser([
            'username' => $username,
            'email' => $email,
            'password' => 'Password123!',
        ]);
        $user->active = $active;

        $users->save($user);

        $saved = $users->findById($users->getInsertID());

        $this->assertNotNull($saved);

        return $saved;
    }

    private function makeUser(string $email, string $group)
    {
        $users = $this->userModel();

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

    private function userModel(): UserModel
    {
        /** @var UserModel $users */
        $users = model(setting('Auth.userProvider'), false);

        return $users;
    }
}
