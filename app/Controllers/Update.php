<?php

namespace App\Controllers;

use App\Database\Seeds\DataSourcesSeeder;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Shield\Models\UserModel;
use Config\Auth;

class Update extends BaseController
{

    public function index(): string|RedirectResponse
    {
        if ($this->request->getMethod() === 'POST') {
            return $this->handleSubmit();
        }
        $runner = service('migrations');
        // Match `php spark migrate --all` so package migrations (e.g. Shield)
        // are included, not only the App namespace.
        $runner->setNamespace(null);
        $migrations = $runner->findMigrations();
        foreach ($runner->getHistory((string) null) as $history) {
            unset($migrations[$runner->getObjectUid($history)]);
        }
        return $this->renderPage('update/index', [
            'pageTitle' => 'Update',
            'metaDescription' => 'Run application database updates.',
            'bodyClass' => 'app-shell auth-shell',
            'navItems' => [],
            'migrationCount' => count($migrations),
        ]);
    }

    private function handleSubmit(): string|RedirectResponse
    {
        try {
            $this->runMigrations();
            $this->runSeeders();
        } catch (\Throwable $exception) {
            return redirect()->back()->withInput()->with('error', $exception->getMessage());
        }
        session()->setFlashdata('message', 'Update complete.');
        return $this->renderPage('update/index', [
            'pageTitle' => 'Update',
            'metaDescription' => 'Update complete.',
            'bodyClass' => 'app-shell auth-shell',
            'navItems' => [],
            'migrationCount' => 0,
        ]);
    }

    private function runMigrations(): void
    {
        $runner = service('migrations');
        // Match `php spark migrate --all` so package migrations (e.g. Shield)
        // are included, not only the App namespace.
        $runner->setNamespace(null);
        if (! $runner->latest()) {
            $messages = $runner->getCliMessages();
            throw new DatabaseException(is_array($messages) ? implode("\n", $messages) : 'Database migrations failed.');
        }
    }

    private function runSeeders(): void
    {
        $seeder = \Config\Database::seeder();
        $seeder->call(DataSourcesSeeder::class);
    }

}
