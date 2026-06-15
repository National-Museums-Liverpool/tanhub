<?php

namespace App\Controllers;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Models\UserModel;
use Config\Auth;

class SetupAdminUser extends BaseController
{
    private const LOCK_FILE = WRITEPATH . 'setupAdminUser.lock';

    public function index(): string|RedirectResponse
    {
        if ($this->isSetupLocked()) {
            throw PageNotFoundException::forPageNotFound();
        }
        if ($this->request->getMethod() === 'POST') {
            return $this->handleSubmit();
        }

        return $this->renderPage('setup-admin-user/index', [
            'pageTitle' => 'Setup',
            'metaDescription' => 'Create the first administrator account.',
            'bodyClass' => 'app-shell auth-shell',
            'navItems' => [],
        ]);
    }

    private function handleSubmit(): RedirectResponse
    {
        $rules = [
            'name' => 'required|min_length[2]|max_length[80]',
            'email' => 'required|valid_email|max_length[254]',
            'password' => 'required|min_length[8]',
            'password_confirm' => 'required|matches[password]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            $user = $this->createAdminUser(
                (string) $this->request->getPost('name'),
                (string) $this->request->getPost('email'),
                (string) $this->request->getPost('password'),
            );
            $lockWritten = $this->writeLockFile($user->email ?? (string) $this->request->getPost('email'));
        } catch (\Throwable $exception) {
            return redirect()->back()->withInput()->with('error', $exception->getMessage());
        }

        $message = 'Setup complete. Sign in with the administrator account you just created.';

        if (! $lockWritten) {
            $message .= ' The administrator account was created, but the setup lock file could not be written. Because a user now exists, /setup will no longer open, but you should still verify writable permissions.';
        }

        return redirect()->to(site_url('login'))->with('message', $message);
    }

    private function createAdminUser(string $name, string $email, string $password)
    {
        if ($this->hasAnyUsers()) {
            throw new DatabaseException('Setup is only available before the first account is created.');
        }

        /** @var UserModel $users */
        $users = model(setting('Auth.userProvider'));

        $username = $this->makeUsername($name, $email);
        $user = $users->createNewUser([
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ]);

        $users->save($user);

        $user = $users->findById($users->getInsertID());

        if ($user === null) {
            throw new DatabaseException('Administrator account could not be loaded after creation.');
        }

        $users->addToDefaultGroup($user);
        $user->addGroup('admin');

        $user->activate();
        $users->save($user);

        return $user;
    }

    private function hasAnyUsers(): bool
    {
        $db = db_connect(config(Auth::class)->DBGroup);
        $tables = config(Auth::class)->tables;

        if (! $db->tableExists($tables['users'])) {
            return false;
        }
        return $db->table($tables['users'])->countAllResults() > 0;
    }

    private function isSetupLocked(): bool
    {
        return is_file(self::LOCK_FILE) || $this->hasAnyUsers();
    }

    private function writeLockFile(string $email): bool
    {
        $contents = implode("\n", [
            'Setup completed: ' . date(DATE_ATOM),
            'Admin email: ' . $email,
        ]) . "\n";

        return @file_put_contents(self::LOCK_FILE, $contents, LOCK_EX) !== false;
    }

    private function makeUsername(string $name, string $email): string
    {
        $base = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));

        if ($base === '') {
            $base = strtolower((string) strstr($email, '@', true));
        }

        return substr($base, 0, 30);
    }
}