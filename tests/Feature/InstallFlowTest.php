<?php

namespace Tests;

use App\Services\InstallStatusService;
use Config\Auth;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * @internal
 */
final class InstallFlowTest extends CIUnitTestCase
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

    public function testHomeRedirectsToUpdateWhenSetupIncompleteAndMigrationsPending(): void
    {
        $this->injectInstallStatus(false, 2);

        $result = $this->get('/');

        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('update'));
    }

    public function testUpdatePageShowsInstallWelcomeWhenSetupIncomplete(): void
    {
        $this->injectInstallStatus(false, 2);

        $result = $this->get('update');

        $result->assertStatus(200);
        $result->assertSee('Welcome to tanhub installation');
        $result->assertSee('database updates available');
    }

    public function testUpdatePostRedirectsToSetupAdminUserWhenSetupIncomplete(): void
    {
        $this->injectInstallStatus(false, 0);

        $result = $this->post('update');

        $result->assertStatus(302);
        $result->assertRedirectTo(site_url('setup-admin-user'));
        $result->assertSessionHas('message');
    }

    public function testLoggedInHomeShowsPendingMigrationWarningWithUpdateLink(): void
    {
        $this->injectInstallStatus(true, 3);
        $this->authenticateAs('install-warning@example.com', 'user');

        $result = $this->get('/');

        $result->assertStatus(200);
        $result->assertSee('database updates are available');
        $result->assertSee(site_url('update'));
    }

    public function testHomeRendersWithoutWarningWhenNoPendingMigrations(): void
    {
        $this->injectInstallStatus(true, 0);

        $result = $this->get('/');

        $result->assertStatus(200);
        $result->assertDontSee('database updates are available');
        $result->assertSee('The Tanyptera Project DB Hub');
    }

    public function testUpdatePageHidesInstallWelcomeWhenSetupComplete(): void
    {
        $this->injectInstallStatus(true, 0);

        $result = $this->get('update');

        $result->assertStatus(200);
        $result->assertDontSee('Welcome to tanhub installation');
        $result->assertSee('No database updates available.');
    }

    private function injectInstallStatus(bool $setupComplete, int $pendingMigrationCount): void
    {
        $mock = $this->createMock(InstallStatusService::class);
        $mock->method('isSetupComplete')->willReturn($setupComplete);
        $mock->method('getPendingMigrationCount')->willReturn($pendingMigrationCount);

        \Config\Services::injectMock('installStatusService', $mock);
    }

    private function authenticateAs(string $email, string $group): void
    {
        $this->actingAs($this->makeUser($email, $group));
        $this->withSession($_SESSION);
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