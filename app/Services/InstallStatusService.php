<?php

namespace App\Services;

use Config\Auth;

/**
 * Provides installation state checks used by setup and update flows.
 *
 * Used by the setup/update controllers to decide whether to show the initial
 * admin-user setup screen or the pending-migrations warning, before any
 * import tasks can be run.
 */
class InstallStatusService
{
    /**
     * Absolute path to the marker file written once initial admin setup has
     * completed, so setup is not re-offered after the first admin user is
     * deleted or renamed.
     */
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
     * Compares every migration file discoverable on the default namespace
     * against the migration history table, removing any migration whose
     * unique object ID already appears in history. What remains is the set
     * of pending (not-yet-run) migrations.
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