<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Default public-facing homepage controller.
 *
 * Also acts as the entry point for the install flow: if pending migrations
 * exist and initial setup has not completed, visitors are redirected to
 * {@see Update} before the homepage is ever rendered.
 */
class Home extends BaseController
{
    /**
     * Render the homepage.
     *
     * Redirects to `/update` when database migrations are pending and
     * initial setup has not yet completed. Otherwise renders the homepage,
     * including a migration-available warning banner for logged-in users
     * when non-blocking migrations are pending, and summary counts from
     * {@see \App\Services\HomeCountsService}.
     *
     * @return string|RedirectResponse Rendered homepage, or a redirect to `/update`.
     */
    public function index(): string|RedirectResponse
    {
        /** @var \App\Services\InstallStatusService $installStatus */
        $installStatus = service('installStatusService');
        /** @var \App\Services\HomeCountsService $homeCountsService */
        $homeCountsService = service('homeCountsService');

        $pendingMigrationCount = $installStatus->getPendingMigrationCount();
        $setupComplete = $installStatus->isSetupComplete();

        if (! $setupComplete && $pendingMigrationCount > 0) {
            return redirect()->to(site_url('update'));
        }

        $isLoggedIn = false;

        try {
            $isLoggedIn = auth()->loggedIn();
        } catch (\Throwable) {
            $isLoggedIn = false;
        }

        $migrationWarningMessage = null;

        if ($isLoggedIn && $pendingMigrationCount > 0) {
            $migrationWarningMessage = $pendingMigrationCount === 1
                ? 'A database update is available.'
                : $pendingMigrationCount . ' database updates are available.';
        }

        $homeCounts = $homeCountsService->getCounts();

        return $this->renderPage('home', [
            'pageTitle' => 'Home',
            'heroTitle' => 'The Tanyptera Project DB Hub',
            'heroCopy' => 'Providing a centralised reporting service for wildlife observation and species data.',
            'migrationWarningMessage' => $migrationWarningMessage,
            'migrationWarningUrl' => site_url('update'),
            'homeCounts' => $homeCounts,

        ]);
    }
}
