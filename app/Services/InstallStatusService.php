<?php

namespace App\Services;

use Config\Auth;

/**
 * Provides installation state checks used by setup and update flows.
 */
class InstallStatusService
{
    private const SETUP_LOCK_FILE = WRITEPATH . 'setupAdminUser.lock';

    /**
     * Determine whether initial administrator setup is complete.
     *
     * Setup is considered complete when a setup lock file exists or at least
     * one user row exists.
     *
     * @return bool True when setup should be treated as complete.
     */
    public function isSetupComplete(): bool
    {
        return is_file(self::SETUP_LOCK_FILE) || $this->hasAnyUsers();
    }

    /**
     * Count migrations that have not yet been applied.
     *
     * @return int Number of pending migrations.
     */
    public function getPendingMigrationCount(): int
    {
        $runner = service('migrations');
        $runner->setNamespace(null);

        $migrations = $runner->findMigrations();

        foreach ($runner->getHistory((string) null) as $history) {
            unset($migrations[$runner->getObjectUid($history)]);
        }

        return count($migrations);
    }

    /**
     * Determine whether any users currently exist.
     *
     * @return bool True when at least one user row exists.
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
}