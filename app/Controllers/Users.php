<?php

namespace App\Controllers;

use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Admin-only user management pages.
 */
class Users extends BaseController
{
    /**
     * Display a paginated, sortable list of users.
     *
     * @return string
     */
    public function index(): string
    {
        $sort = strtolower((string) $this->request->getGet('sort'));
        $direction = strtolower((string) $this->request->getGet('direction'));
        $q = trim((string) $this->request->getGet('q'));

        $allowedSortColumns = ['id', 'username', 'active', 'created_at'];

        if (! in_array($sort, $allowedSortColumns, true)) {
            $sort = 'username';
        }

        if (! in_array($direction, ['asc', 'desc'], true)) {
            $direction = 'asc';
        }

        $users = $this->userModel()->withIdentities()->withGroups();

        if ($q !== '') {
            $this->applySearch($users, $q);
        }

        $results = $users
            ->orderBy($sort, $direction)
            ->paginate(20);

        return $this->renderPage('users/index', [
            'pageTitle' => 'Users',
            'metaDescription' => 'User accounts list.',
            'bodyClass' => 'app-shell',
            'users' => $results,
            'pager' => $users->pager,
            'sort' => $sort,
            'direction' => $direction,
            'q' => $q,
        ]);
    }

    /**
     * Render the create-user form.
     *
     * @return string
     */
    public function create(): string
    {
        return $this->renderPage('users/create', [
            'pageTitle' => 'Create user',
            'metaDescription' => 'Create a user account.',
            'bodyClass' => 'app-shell auth-page',
            'groupOptions' => $this->allowedGroups(),
        ]);
    }

    /**
     * Create a user from submitted form data.
     *
     * @return RedirectResponse
     */
    public function store(): RedirectResponse
    {
        $rules = [
            'username' => 'required|min_length[3]|max_length[30]|regex_match[/^[A-Za-z0-9._-]+$/]',
            'email' => 'required|valid_email|max_length[254]',
            'active' => 'required|in_list[0,1]',
            'groups' => 'required',
            'password' => 'required|min_length[8]',
            'password_confirm' => 'required|matches[password]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $username = trim((string) $this->request->getPost('username'));
        $email = strtolower(trim((string) $this->request->getPost('email')));
        $active = (int) $this->request->getPost('active') === 1;
        $groups = $this->sanitizeGroups($this->request->getPost('groups'));
        $password = (string) $this->request->getPost('password');

        if ($groups === []) {
            return redirect()->back()->withInput()->with('errors', ['groups' => 'Select at least one group.']);
        }

        if ($this->isUsernameTaken($username)) {
            return redirect()->back()->withInput()->with('errors', ['username' => 'That username is already in use.']);
        }

        if ($this->isEmailTaken($email)) {
            return redirect()->back()->withInput()->with('errors', ['email' => 'That email address is already in use.']);
        }

        $users = $this->userModel();

        $user = $users->createNewUser([
            'username' => $username,
            'email' => $email,
            'password' => $password,
        ]);

        $user->active = $active;

        $users->save($user);

        /** @var User|null $saved */
        $saved = $users->findById($users->getInsertID());

        if ($saved === null) {
            return redirect()->back()->withInput()->with('error', 'User could not be created.');
        }

        $this->syncGroups($saved, $groups);

        return redirect()->to(site_url('users/' . $saved->id))->with('message', 'User created.');
    }

    /**
     * Render the edit form for a single user.
     *
     * @param int $id
     * @return string
     */
    public function details(int $id): string
    {
        $user = $this->findUser($id);

        return $this->renderPage('users/details', [
            'pageTitle' => 'Edit user',
            'metaDescription' => 'Edit user account settings.',
            'bodyClass' => 'app-shell auth-page',
            'managedUser' => $user,
            'email' => (string) ($user->getEmail() ?? ''),
            'groups' => $user->getGroups() ?? [],
            'groupOptions' => $this->allowedGroups(),
        ]);
    }

    /**
     * Update an existing user account.
     *
     * @param int $id
     * @return RedirectResponse
     */
    public function update(int $id): RedirectResponse
    {
        $user = $this->findUser($id);

        $rules = [
            'username' => 'required|min_length[3]|max_length[30]|regex_match[/^[A-Za-z0-9._-]+$/]',
            'email' => 'required|valid_email|max_length[254]',
            'active' => 'required|in_list[0,1]',
            'groups' => 'required',
            'password' => 'permit_empty|min_length[8]',
            'password_confirm' => 'permit_empty|matches[password]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $username = trim((string) $this->request->getPost('username'));
        $email = strtolower(trim((string) $this->request->getPost('email')));
        $active = (int) $this->request->getPost('active') === 1;
        $groups = $this->sanitizeGroups($this->request->getPost('groups'));
        $password = (string) $this->request->getPost('password');

        if ($groups === []) {
            return redirect()->back()->withInput()->with('errors', ['groups' => 'Select at least one group.']);
        }

        if ($this->isUsernameTaken($username, (int) $user->id)) {
            return redirect()->back()->withInput()->with('errors', ['username' => 'That username is already in use.']);
        }

        if ($this->isEmailTaken($email, (int) $user->id)) {
            return redirect()->back()->withInput()->with('errors', ['email' => 'That email address is already in use.']);
        }

        $user->username = $username;
        $user->email = $email;
        $user->active = $active;

        if ($password !== '') {
            $user->setPassword($password);
        }

        $this->userModel()->save($user);
        $this->syncGroups($user, $groups);

        return redirect()->to(site_url('users/' . $id))->with('message', 'User updated.');
    }

    /**
     * Return valid group values used by the user admin forms.
     *
     * @return array<int, string>
     */
    private function allowedGroups(): array
    {
        return ['user', 'manager', 'admin'];
    }

    /**
     * Normalize posted group values to allowed, unique group keys.
     *
     * @param mixed $groups
     * @return array<int, string>
     */
    private function sanitizeGroups($groups): array
    {
        if (! is_array($groups)) {
            return [];
        }

        $allowed = $this->allowedGroups();
        $selected = [];

        foreach ($groups as $group) {
            $key = strtolower(trim((string) $group));

            if (in_array($key, $allowed, true)) {
                $selected[] = $key;
            }
        }

        return array_values(array_unique($selected));
    }

    /**
     * Synchronize Shield group memberships for user admin-managed groups.
     *
     * @param User $user
     * @param array<int, string> $groups
     * @return void
     */
    private function syncGroups(User $user, array $groups): void
    {
        $allowed = $this->allowedGroups();
        $selected = array_values(array_intersect($allowed, $groups));

        if ($selected === []) {
            $selected = ['user'];
        }

        $user->removeGroup(...$allowed);
        $user->addGroup(...$selected);
    }

    /**
     * Build search conditions for username and email.
     *
     * @param UserModel $users
     * @param string $q
     * @return void
     */
    private function applySearch(UserModel $users, string $q): void
    {
        $db = db_connect();
        $identityTable = $this->authTable('identities');
        $escapedType = $db->escape('email_password');
        $like = '%' . strtolower($db->escapeLikeString($q)) . '%';
        $escapedLike = $db->escape($like);

        $users->groupStart()
            ->like('username', $q)
            ->orWhere(
                'id IN (SELECT user_id FROM ' . $identityTable . ' WHERE type = ' . $escapedType . ' AND LOWER(secret) LIKE ' . $escapedLike . " ESCAPE '!')",
                null,
                false,
            )
            ->groupEnd();
    }

    /**
     * Return a Shield UserModel instance.
     *
     * @return UserModel
     */
    private function userModel(): UserModel
    {
        /** @var UserModel $users */
        $users = model(setting('Auth.userProvider'), false);

        return $users;
    }

    /**
     * Find a user by id or throw 404.
     *
     * @param int $id
     * @return User
     */
    private function findUser(int $id): User
    {
        $user = $this->userModel()->withIdentities()->withGroups()->findById($id);

        if ($user === null) {
            throw PageNotFoundException::forPageNotFound();
        }

        return $user;
    }

    /**
     * Determine whether a username is already assigned to a different user.
     *
     * @param string $username
     * @param int|null $excludeUserId
     * @return bool
     */
    private function isUsernameTaken(string $username, ?int $excludeUserId = null): bool
    {
        $users = $this->userModel();
        $query = $users->where('LOWER(username)', strtolower($username));

        if ($excludeUserId !== null) {
            $query = $query->where('id !=', $excludeUserId);
        }

        return $query->first() !== null;
    }

    /**
     * Determine whether an email is already assigned to a different user.
     *
     * @param string $email
     * @param int|null $excludeUserId
     * @return bool
     */
    private function isEmailTaken(string $email, ?int $excludeUserId = null): bool
    {
        $identityTable = $this->authTable('identities');
        $db = db_connect();

        $builder = $db->table($identityTable)
            ->select('id')
            ->where('type', 'email_password')
            ->where('LOWER(secret)', strtolower($email));

        if ($excludeUserId !== null) {
            $builder->where('user_id !=', $excludeUserId);
        }

        return $builder->get()->getRowArray() !== null;
    }

    /**
     * Resolve an auth-related table name from Shield config.
     *
     * @param string $key
     * @return string
     */
    private function authTable(string $key): string
    {
        $tables = config(\Config\Auth::class)->tables;

        return (string) ($tables[$key] ?? $key);
    }
}
