<?php

namespace App\Controllers;

use App\Database\Seeds\DataSourcesSeeder;
use App\Services\InstallStatusService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\HTTP\RedirectResponse;
use Config\Import as ImportConfig;

/**
 * Handles database migrations and seed updates from the web UI.
 *
 * This is the first step of the install/upgrade flow: it runs any pending
 * migrations (including package migrations such as Shield's) and the
 * baseline lookup-data seeder, then hands off to {@see SetupAdminUser} if
 * no administrator account exists yet, or reports success otherwise.
 */
class Update extends BaseController
{
    /**
     * Show update status or process an update request.
     *
     * @return string|RedirectResponse Rendered update page for GET requests,
     *                                 or the result of {@see self::handleSubmit()} for POST.
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
     *
     * Runs migrations then seeders; on any failure, redirects back with the
     * exception message so the operator can diagnose the problem (this page
     * is admin/installer-only, so surfacing raw exception text is
     * acceptable here). On success, redirects to the admin setup page if no
     * administrator account exists yet, otherwise re-renders the update page
     * with a success flash message.
     *
     * @return string|RedirectResponse Redirect on failure or when handing off to setup,
     *                                 or rendered update page on success.
     * @throws \Throwable Not thrown to the caller; caught internally and converted to a redirect.
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
     *
     * @return void
     * @throws DatabaseException If the migration runner reports failure.
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
     *
     * Currently only runs {@see DataSourcesSeeder}; additional baseline
     * seeders should be added here rather than in migrations (see repo
     * convention: baseline `data_sources` records live in the seeder, not
     * migrations).
     *
     * @return void
     */
    private function runSeeders(): void
    {
        $seeder = \Config\Database::seeder();
        $seeder->call(DataSourcesSeeder::class);
    }
}
