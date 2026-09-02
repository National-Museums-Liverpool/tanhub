<?php

namespace Tests;

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Auth;

/**
 * @internal
 */
final class AccountPagesTest extends CIUnitTestCase
{
    use FeatureTestTrait;
    use AuthenticationTesting;

    /**
     * Prepare the database and authentication state for each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        \Config\Services::reset();
        $_SESSION = [];
        $_COOKIE = [];
        $this->withSession([]);
        config(Auth::class)->actions['register'] = null;

        $migrate = service('migrations');
        $migrate->setNamespace(null);
        $migrate->latest();
    }

    /**
     * Verify that unauthenticated users cannot access the account page.
     *
     * @return void
     */
    public function testAccountRequiresLogin(): void
    {
        $result = $this->get('account');

        $result->assertStatus(302);
        $result->assertRedirect();
    }

    /**
     * Verify that a user can update their profile without changing password.
     *
     * @return void
     */
    public function testUserCanUpdateProfileWithoutChangingPassword(): void
    {
        $user = $this->makeUser('account-profile@example.com');

        $result = $this->post('account', [
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'account-profile@example.com',
            'current_password' => '',
            'new_password' => '',
            'new_password_confirm' => '',
        ]);

        $result->assertStatus(302);
        $saved = $this->userModel()->findById((int) $user->id);
        $this->assertNotNull($saved);
        $this->assertSame('Ada', $saved->first_name);
        $this->assertSame('Lovelace', $saved->last_name);
    }

    /**
     * Verify that a password change requires the current password.
     *
     * @return void
     */
    public function testPasswordChangeRequiresCurrentPassword(): void
    {
        $this->makeUser('account-password@example.com');

        $result = $this->post('account', [
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'account-password@example.com',
            'current_password' => 'WrongPassword!',
            'new_password' => 'NewPassword123!',
            'new_password_confirm' => 'NewPassword123!',
        ]);

        $result->assertRedirect();
        $this->assertSame('Enter your current password to change your email or password.', session('errors.current_password'));
    }

    /**
     * Verify that a user can change password with the current password.
     *
     * @return void
     */
    public function testUserCanChangePasswordWithCurrentPassword(): void
    {
        $user = $this->makeUser('account-change@example.com');

        $result = $this->post('account', [
            'first_name' => 'Katherine',
            'last_name' => 'Johnson',
            'email' => 'account-change@example.com',
            'current_password' => 'Password123!',
            'new_password' => 'NewPassword123!',
            'new_password_confirm' => 'NewPassword123!',
        ]);

        $result->assertRedirectTo(site_url('account'));
        $saved = $this->userModel()->findById((int) $user->id);
        $this->assertNotNull($saved);
        $this->assertTrue(service('passwords')->verify('NewPassword123!', (string) $saved->password_hash));
    }

    /**
     * Verify that a magic-link user can set a password without the old one.
     *
     * @return void
     */
    public function testUserCanSetPasswordAfterMagicLinkLogin(): void
    {
        $user = $this->makeUser('account-magic-link@example.com');
        session()->setTempdata('magicLogin', true);
        $this->withSession($_SESSION);
        $this->assertSame(site_url('account'), config(Auth::class)->loginRedirect());

        $result = $this->post('account', [
            'first_name' => 'Test',
            'last_name' => 'User',
            'email' => 'account-magic-link@example.com',
            'current_password' => '',
            'new_password' => 'NewPassword123!',
            'new_password_confirm' => 'NewPassword123!',
        ]);

        $result->assertRedirectTo(site_url('account'));
        $saved = $this->userModel()->findById((int) $user->id);
        $this->assertNotNull($saved);
        $this->assertTrue(service('passwords')->verify('NewPassword123!', (string) $saved->password_hash));
        $this->assertNull(session()->getTempdata('magicLogin'));
    }

    /**
     * Create and authenticate an active test user.
     *
     * @param string $email User email address.
     * @return User
     */
    private function makeUser(string $email): User
    {
        $users = $this->userModel();
        $user = $users->createNewUser([
            'username' => strstr($email, '@', true),
            'email' => $email,
            'password' => 'Password123!',
            'first_name' => 'Test',
            'last_name' => 'User',
        ]);
        $users->save($user);

        $saved = $users->findById($users->getInsertID());
        $this->assertNotNull($saved);
        $saved->activate();
        $users->save($saved);
        $this->actingAs($saved);
        $this->withSession($_SESSION);

        return $saved;
    }

    /**
     * Return the configured user model.
     *
     * @return UserModel
     */
    private function userModel(): UserModel
    {
        /** @var UserModel $users */
        $users = model(setting('Auth.userProvider'), false);

        return $users;
    }
}