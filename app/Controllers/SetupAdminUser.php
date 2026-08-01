<?php

namespace App\Controllers;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Models\UserModel;
use Config\Auth;

/**
 * One-time setup flow for creating the first administrator account.
 *
 * Only reachable before any user exists in the database and before the
 * lock file ({@see self::LOCK_FILE}) has been written; after either
 * condition is met the route 404s. Runs after {@see Update} has applied
 * pending migrations, and hands off to the login page once complete.
 */
class SetupAdminUser extends BaseController
{
    /**
     * Path to the marker file written once setup has completed successfully.
     *
     * Its presence (in addition to the "any users exist" check in
     * {@see self::hasAnyUsers()}) is used to lock the `/setup-admin-user`
     * route so it cannot be used again, even if the users table were later
     * emptied.
     */
    private const LOCK_FILE = WRITEPATH . 'setupAdminUser.lock';

    /**
     * Show the setup form or process submitted setup data.
     *
     * Blocks access entirely (404) once setup is locked; otherwise renders
     * the form for GET requests and delegates to {@see self::handleSubmit()}
     * for POST requests.
     *
     * @return string|RedirectResponse Rendered setup form, or a redirect on submit.
     * @throws PageNotFoundException If setup has already been completed/locked.
     */
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

    /**
     * Validate setup form data and create the first administrator.
     *
     * On validation failure, redirects back with field errors. On any
     * failure while creating the account or writing the lock file, redirects
     * back with a generic error message (the underlying exception message is
     * surfaced to help diagnose setup issues). If the account is created but
     * the lock file cannot be written, setup still succeeds (an existing user
     * already blocks the route via {@see self::hasAnyUsers()}) but a warning
     * is appended to the success message.
     *
     * @return RedirectResponse Redirect to the login page on success, or back with errors.
     */
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

    /**
     * Create and activate the initial administrator account.
     *
     * Refuses to run if any user already exists, guarding against this
     * method being invoked outside of the locked setup route. The created
     * user is added to the default Shield group and then explicitly granted
     * the `admin` group before being activated (no email verification step).
     *
     * @param string $name     Display name used to derive the username.
     * @param string $email    Administrator email address.
     * @param string $password Plaintext password to be hashed by Shield.
     * @return object Newly created and activated Shield user entity.
     * @throws DatabaseException If a user already exists or the created user cannot be reloaded.
     */
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

    /**
     * Determine whether any users already exist.
     *
     * Treats a missing users table (e.g. before migrations have run) as "no
     * users" rather than erroring, so this can be safely called early in the
     * install flow.
     *
     * @return bool True if at least one row exists in the Shield users table.
     */
    private function hasAnyUsers(): bool
    {
        $db = db_connect(config(Auth::class)->DBGroup);
        $tables = config(Auth::class)->tables;

        if (! $db->tableExists($tables['users'])) {
            return false;
        }
        return $db->table($tables['users'])->countAllResults() > 0;
    }

    /**
     * Determine whether the setup route should be locked.
     *
     * Locked once the lock file exists (see {@see self::LOCK_FILE}) or once
     * any user account exists, whichever happens first.
     *
     * @return bool True if `/setup-admin-user` should 404.
     */
    private function isSetupLocked(): bool
    {
        return is_file(self::LOCK_FILE) || $this->hasAnyUsers();
    }

    /**
     * Write a lock file to mark setup as complete.
     *
     * Uses `@` error suppression because a failed write should not abort the
     * setup flow (the admin account has already been created); the caller
     * inspects the boolean result to decide whether to warn the user.
     *
     * @param string $email Administrator email recorded in the lock file for auditing.
     * @return bool True if the lock file was written successfully.
     */
    private function writeLockFile(string $email): bool
    {
        $contents = implode("\n", [
            'Setup completed: ' . date(DATE_ATOM),
            'Admin email: ' . $email,
        ]) . "\n";

        return @file_put_contents(self::LOCK_FILE, $contents, LOCK_EX) !== false;
    }

    /**
     * Generate a username from the supplied name or email.
     *
     * Slugifies the name (lowercased, non-alphanumeric runs collapsed to a
     * single hyphen); falls back to the email local-part if the name yields
     * an empty slug. Truncated to fit the `username` column length.
     *
     * @param string $name  Administrator display name, as submitted.
     * @param string $email Administrator email, used as a fallback source.
     * @return string Username candidate, at most 30 characters.
     */
    private function makeUsername(string $name, string $email): string
    {
        $base = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name), '-'));

        if ($base === '') {
            $base = strtolower((string) strstr($email, '@', true));
        }

        return substr($base, 0, 30);
    }
}