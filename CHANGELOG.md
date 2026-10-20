# Changelog

## 1.1.0

### Features

- Ability to download taxonomy data from the admin UI. See
  https://github.com/National-Museums-Liverpool/tanhub/issues/8.
- Ability to download occurrence data from the admin UI. See
  https://github.com/National-Museums-Liverpool/tanhub/issues/7.
- Ability to use geographic regions from multiple location type layers, e.g. Vice Counties and
  Local Record Centre boundaries. See https://github.com/National-Museums-Liverpool/tanhub/issues/10
  and detailed notes below.
- Ability to flag key species, either globally or at a regional level, with comment and decision
  date. See https://github.com/National-Museums-Liverpool/tanhub/issues/13.
- Addition of average square and record counts per year to `taxon_stats` table. See
  https://github.com/National-Museums-Liverpool/tanhub/issues/12.

### Bugfixes

- `Occurrences.identification_verification_status` field now consistently uses 'V' for verified
  records instead of 'Ac' when imported from the NBN Atlas. See
  https://github.com/National-Museums-Liverpool/tanhub/issues/18.
- Fix issue where a taxon with only unverified records could get a `first_verified_record_date`
  value in the `taxon_stats` table, so appearing to be verified.
- Fixes issues with completing population of data on a newly configured server. See
  https://github.com/National-Museums-Liverpool/tanhub/issues/9.

### Added and changed

- Import vice counties and Local Record Centre (LRC) boundaries together using installation-time
  geography groups. The iRecord type for LRCs is `LRC Boundary`.
- Store each location's iRecord name and an optional NBN Atlas name override. The RECORD name
  has no final full stop in iRecord, but does have one in NBN Atlas.
- Fetch geographic-region and grid-square lookups once per geography group. Fetch Indicia
  occurrences with a combined type/name filter, and NBN occurrences with independent group cursors.
- Keep existing top-level import tasks, occurrence deduplication and square-per-region statistics.
  Overlapping regions are not disjoint: do not sum their counts to calculate global totals.
- Use the same location-code-or-ID identifier in both warehouse geography reports.
- Use `import_offsets`, not old run-history checkpoints, to resume occurrence imports. Clearing
  offsets therefore restarts population even when import history is retained.
- Stop CLI imports with an actionable configuration message when the removed geography settings
  are still present, linking operators to the required JSON configuration.

### Installation and upgrade

Scope is configured at installation, not changed through an admin scope-management workflow.
No schema migration is required for geography groups.

1. Stop scheduled imports, wait for active imports to finish, and back up the database.
2. Replace the old CSV `import.geographicRegions` value with the JSON format in the
   [configuration reference](docs/configuration-reference.md#geographic-regions). Remove
   `import.geographicRegionLocationType`. Do not URL encode names in configuration.
3. Ensure every configured iRecord location exists under the exact type/name, is indexed for
   occurrence filtering, and has a usable boundary polygon.
4. Copy the updated `geographic_regions.xml` and `grid_square_stats.xml` from
   `indicia-warehouse-module/tanhub/reports/projects/tanhub/` to the matching warehouse report
   directory. Updating the local files alone does not update the warehouse.
5. With imports stopped, clear **all rows** from the configured database's `import_offsets`
   table, including any group child rows. Include the table prefix if one is configured.
   Retain occurrence data and import history. Resume population in the normal dependency order
   using the Imports page or `php spark import:auto`, until all lookup and occurrence groups finish.
   Pause scheduling again before the membership and statistics steps below.
6. If retaining existing occurrence rows, perform the full membership rebuild below.
7. Rebuild derived statistics in dependency order, then verify regional results before restoring
   normal scheduling.

### Full occurrence-region membership rebuild

**Clearing offsets and reimporting is not sufficient for existing unchanged occurrences.**
Normal imports rebuild geographic memberships only for new or statistics-relevant changed rows.
An unchanged occurrence that already belongs to a vice county will not automatically gain a new
LRC membership. Fresh installations do not need this extra step.

After all regions, grid squares and occurrences have been imported, run this one-off invocation
from the project root, using the same PHP version, environment and filesystem permissions as
the normal CLI imports. Keep scheduling paused. This uses the existing service and import lock;
it is not a new shipped maintenance command.

```bash
php <<'PHP'
<?php
define('FCPATH', getcwd() . '/public/');
require 'vendor/autoload.php';
(new \CodeIgniter\Config\DotEnv(getcwd()))->load();
define('ENVIRONMENT', $_ENV['CI_ENVIRONMENT'] ?? $_SERVER['CI_ENVIRONMENT']
    ?? (getenv('CI_ENVIRONMENT') ?: 'production'));
require 'app/Config/Paths.php';
$paths = new \Config\Paths();
require $paths->systemDirectory . '/Boot.php';
\CodeIgniter\Boot::bootConsole($paths);

$lock = service('importLock');
if (! $lock->acquire()) {
    throw new \RuntimeException('Another import is running. Try again after it finishes.');
}

try {
    $result = service('geographicRegionsOccurrenceImportService')->run(false, null);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
    if (($result['status'] ?? '') !== 'success' || ($result['errors'] ?? 0) !== 0) {
        throw new \RuntimeException('Membership rebuild failed. Check application logs before continuing.');
    }
} finally {
    $lock->release();
}
PHP
```

The `null` occurrence-ID argument selects **all** eligible occurrences. Successful completion
replaces their memberships with all matching active regional polygons, including VC/LRC overlaps.
This is a full in-memory rebuild: run it with sufficient CLI memory and time for your installation.
It replaces links without an atomic table swap, so schedule a maintenance window and rerun on
failure before publishing results. Keep the backup until validation is complete.

Next, rebuild statistics. Use each `--restart` option **once**, then continue a resumable task
without `--restart` until it completes:

```bash
php spark stats:grid-square-stats --restart
php spark stats:taxon-rarity
php spark stats:taxon-year-stats --restart
# If unfinished, repeat without --restart until yearly statistics are complete:
php spark stats:taxon-year-stats
php spark stats:taxon-stats --restart
# If unfinished, repeat without --restart until taxon statistics are complete:
php spark stats:taxon-stats
```

The full membership rebuild does not enqueue incremental statistics work. Complete these full
statistics rebuilds even if the normal incremental queue appears empty. Confirm completion on
the Imports page and check logs for errors. See [Import](docs/import.md) for dependencies.
