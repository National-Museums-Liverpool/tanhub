<?php

namespace App\Controllers;

use CodeIgniter\HTTP\RedirectResponse;

/**
 * Default public-facing homepage controller.
 */
class Home extends BaseController
{
    /**
     * Render the homepage.
     *
     * @return string|RedirectResponse
     */
    public function index(): string|RedirectResponse
    {
        /** @var \App\Services\InstallStatusService $installStatus */
        $installStatus = service('installStatusService');

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

        return $this->renderPage('home', [
            'pageTitle' => 'Home',
            'heroTitle' => 'The Tanyptera Project DB Hub',
            'heroCopy' => 'Providing a centralised reporting service for wildlife observation and species data.',
            'migrationWarningMessage' => $migrationWarningMessage,
            'migrationWarningUrl' => site_url('update'),

        ]);
    }
}
