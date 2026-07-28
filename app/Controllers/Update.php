<?php

namespace App\Controllers;

use App\Database\Seeds\DataSourcesSeeder;
use App\Services\InstallStatusService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Import as ImportConfig;

/**
 * Handles database migrations and seed updates from the web UI.
 */
class Update extends BaseController
{
    /**
     * Show update status or process an update request.
     */
    public function index(): string|RedirectResponse
    {
        if ($this->request->getMethod() === 'POST') {
            return $this->handleSubmit();
        }

        /** @var InstallStatusService $installStatus */
        $installStatus = service('installStatusService');

        return $this->renderPage('update/index', [
            'pageTitle' => 'Update',
            'metaDescription' => 'Run application database updates.',
            'bodyClass' => 'app-shell auth-shell',
            'navItems' => [],
            'migrationCount' => $installStatus->getPendingMigrationCount(),
            'showInstallWelcome' => ! $installStatus->isSetupComplete(),
        ]);
    }

    /**
     * Execute the update workflow and render the result page.
     */
    private function handleSubmit(): string|RedirectResponse
    {
        try {
            $this->runMigrations();
            $this->runSeeders();
        } catch (\Throwable $exception) {
            return redirect()->back()->withInput()->with('error', $exception->getMessage());
        }
        /** @var InstallStatusService $installStatus */
        $installStatus = service('installStatusService');

        if (! $installStatus->isSetupComplete()) {
            return redirect()
                ->to(site_url('setup-admin-user'))
                ->with('message', 'Update complete. Continue by creating the first administrator account.');
        }

        session()->setFlashdata('message', 'Update complete.');

        return $this->renderPage('update/index', [
            'pageTitle' => 'Update',
            'metaDescription' => 'Update complete.',
            'bodyClass' => 'app-shell auth-shell',
            'navItems' => [],
            'migrationCount' => 0,
            'showInstallWelcome' => false,
        ]);
    }

    /**
     * Run all pending migrations, including package migrations.
     */
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

    /**
     * Run required seeders for baseline lookup data.
     */
    private function runSeeders(): void
    {
        $seeder = \Config\Database::seeder();
        $seeder->call(DataSourcesSeeder::class);
    }
}
