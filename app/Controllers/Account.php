<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;

/**
 * Provides self-service profile and password management.
 */
class Account extends BaseController
{
    /**
     * Display the signed-in user's account form.
     *
     * @return string
     */
    public function index(): string
    {
        return $this->renderPage('account/index', [
            'pageTitle' => 'My account',
            'metaDescription' => 'Manage your account details and password.',
            'bodyClass' => 'app-shell auth-page',
            'user' => $this->currentUser(),
        ]);
    }

    /**
     * Update the signed-in user's profile and optionally password.
     *
     * @return RedirectResponse
     */
    public function update(): RedirectResponse
    {
        $user = $this->currentUser();
        $newPassword = (string) $this->request->getPost('new_password');
        $email = strtolower(trim((string) $this->request->getPost('email')));

        $rules = [
            'first_name' => 'required|min_length[1]|max_length[100]',
            'last_name' => 'required|min_length[1]|max_length[100]',
            'email' => 'required|valid_email|max_length[254]',
            'current_password' => 'permit_empty|min_length[8]',
            'new_password' => 'permit_empty|min_length[8]',
            'new_password_confirm' => 'permit_empty|matches[new_password]',
        ];

        if (! $this->validate($rules)) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        $currentPassword = (string) $this->request->getPost('current_password');
        $emailChanged = strtolower((string) $user->getEmail()) !== $email;

        if (($emailChanged || $newPassword !== '') && ! $this->passwordIsValid($user, $currentPassword)) {
            return redirect()->back()->withInput()->with('errors', [
                'current_password' => 'Enter your current password to change your email or password.',
            ]);
        }

        $user->first_name = trim((string) $this->request->getPost('first_name'));
        $user->last_name = trim((string) $this->request->getPost('last_name'));
        $user->email = $email;

        if ($newPassword !== '') {
            $user->setPassword($newPassword);
        }

        $this->userModel()->save($user);

        return redirect()->to(site_url('account'))->with('message', 'Account updated.');
    }

    /**
     * Return the currently authenticated user.
     *
     * @return User
     */
    private function currentUser(): User
    {
        /** @var User|null $user */
        $user = auth()->user();

        if ($user === null) {
            throw new \RuntimeException('An authenticated user is required.');
        }

        return $this->userModel()->findById((int) $user->id);
    }

    /**
     * Check the supplied password against the user's email identity.
     *
     * @param User $user
     * @param string $password
     * @return bool
     */
    private function passwordIsValid(User $user, string $password): bool
    {
        $identity = $user->getEmailIdentity();

        return $password !== '' && $identity !== null
            && service('passwords')->verify($password, (string) $identity->secret2);
    }

    /**
     * Return the configured application user model.
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