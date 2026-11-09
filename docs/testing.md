# Testing guide

## Configurable taxon attributes

Portable tests cover deployed cardinality metadata, tolerated singleton source conflicts,
multi-value membership, numeric/label sorting and API/export integration:

```bash
vendor/bin/phpunit -c phpunit.dist.xml --no-coverage \
  tests/unit/Services/TaxonAttributeServiceTest.php
vendor/bin/phpunit -c phpunit.dist.xml --no-coverage \
  tests/Feature/ApiV1LookupResourcesTest.php --filter 'testConfiguredAttributes|testAttributeDiscovery'
vendor/bin/phpunit -c phpunit.dist.xml --no-coverage \
  tests/Feature/ExportsTest.php --filter testAttributeTemplates
```

The optional warehouse SQL test executes the actual XML query using temporary PostgreSQL
fixtures. It accepts only database names ending in `_attributes_validation` and rolls back
all fixture work; it does not contact the real warehouse:

```bash
TANHUB_ATTRIBUTE_PG_DSN='pgsql:host=127.0.0.1;dbname=tanhub_attributes_validation' \
TANHUB_ATTRIBUTE_PG_USER=test_user \
vendor/bin/phpunit -c phpunit.dist.xml --no-coverage \
  tests/Integration/IndiciaTaxonAttributesReportTest.php
```

Set `TANHUB_ATTRIBUTE_PG_PASSWORD` locally when needed. Never provide production connection details.
Native MySQL-compatible collation and migration checks use a separate opt-in test.
Create an empty, dedicated validation database first, grant the test account DDL permissions,
then run:

```bash
TANHUB_ATTRIBUTE_MYSQL_DATABASE=tanhub_attributes_validation \
vendor/bin/phpunit -c phpunit.dist.xml --no-coverage tests/Integration/TaxonAttributeMySqlTest.php
```

The test uses random table prefixes, removes only its own fixtures and accepts only database
names ending in `_attributes_validation`. Connection settings default to the configured connection;
override `TANHUB_ATTRIBUTE_MYSQL_HOST`, `_USER`, `_PASSWORD` and `_PORT` locally when needed.
SQLite verifies portable semantics but cannot certify MySQL label collation or query performance.

To run a synthetic query diagnostic with 100,000 taxa, 1,000,000 assignments and 2,000,000
occurrences, also set `TANHUB_ATTRIBUTE_MYSQL_SCALE=1`. This deliberately expensive opt-in check
seeds isolated fixtures, checks row/hydration counts and reports five warm query-plus-hydration
measurements per shape after a warm-up. It does not assert API response-time budgets: it omits
HTTP processing, API totals, production joins and export work, and uses a uniform distribution
without missing values. Use the full acceptance procedure below before deployment.

The current occurrence-sort performance is accepted for now. A local MariaDB 11.7.2 diagnostic
at that scale measured roughly 3-5.2 seconds for occurrence sorting plus hydration; taxa sorts
were roughly 0.24-1.18 seconds. These limited measurements are not an API latency guarantee.
Further sort projections are deferred; the targets below remain provisional future goals.

Before production acceptance, benchmark a dedicated MySQL-compatible validation database with
100,000 taxa, four attributes, up to 1,000,000 assignments and 2,000,000 occurrences.
Include common/rare terms, missing values, multiple habitats/biotopes and singleton SQS/SAT.
Measure accurate counts, filters, hydration, both attribute sort directions and mixed sorts.

Provisional warm-cache targets are API p95 <= 1 second for limit 50/offset zero, <= 2 seconds at
offset 10,000, and <= 10% regression on requests without attributes. Use at least 30 runs per
shape and record database version, collation, host resources, distributions and index sizes.
Existing 500-row export batches target <= 5 seconds and <= 128 MiB peak PHP memory.
Confirm targets against actual production scale/hosting; do not infer them from portable tests.

Use focused tests while changing API, taxonomy, or media code, then run the full suite before you
commit. The commands below start with the quickest checks and include project-specific regressions.

## Prerequisites

- PHP 8.2+
- Composer dependencies installed

Install dependencies from the project root:

```bash
composer install
```

## Test configuration used by this project

- PHPUnit config file: `phpunit.dist.xml`
- Test suite source: `./tests`
- Coverage/log output directory: `build/logs`
- Composer shortcut: `composer test`

## Run the full suite

From the project root:

```bash
composer test
```

Equivalent command used by this project:

```bash
XDEBUG_MODE=coverage php ./vendor/bin/phpunit -c phpunit.dist.xml
```

## Run only the API lookup resource tests

This is the fastest regression check for the lookup endpoints, including taxon stats.

```bash
vendor/bin/phpunit -c phpunit.dist.xml tests/Feature/ApiV1LookupResourcesTest.php
```

## Run taxonomy redesign regression tests

These tests cover configured rank mappings, complete hierarchy persistence, reporting statistics,
the public API contract, and the read-only admin taxonomy screens:

```bash
vendor/bin/phpunit -c phpunit.dist.xml tests/unit/Config/ImportConfigTest.php
vendor/bin/phpunit -c phpunit.dist.xml \
    tests/unit/Services/Import/Persistence/TaxaImportServiceTest.php
vendor/bin/phpunit -c phpunit.dist.xml \
    tests/unit/Services/Import/Persistence/TaxonRanksImportServiceTest.php
vendor/bin/phpunit -c phpunit.dist.xml \
    tests/unit/Services/Stats/TaxonStatsServiceTest.php \
    tests/unit/Services/Stats/TaxonYearStatsServiceTest.php
vendor/bin/phpunit -c phpunit.dist.xml tests/Feature/ApiV1LookupResourcesTest.php
vendor/bin/phpunit -c phpunit.dist.xml \
    tests/Feature/AdminReferenceTablesTest.php tests/Feature/TaxaPagesTest.php
```

## Run staff search regression tests

These tests cover inclusive date containment, same-record region/year evidence, reporting
ancestors and descendant selection, independent classification/rank filters, moderation,
synonyms, duplicate prevention, protected lookup and invalid filter feedback:

```bash
vendor/bin/phpunit -c phpunit.dist.xml \
    --no-coverage \
    --filter 'TaxonSearchTest|SearchCriteriaTest|TaxaPagesTest|DataSourcesOccurrencesPagesTest'
```

The query service constructs unpaginated queries independently of HTML rendering. CSV exports
reuse its criteria, batch preparation and complete ordering without reimplementing filters.

## Run staff export checks

Portable feature tests cover rolling ID capture, frozen values, grid ordering, null/date ties,
CSV escaping/formula safety, file checkpoint recovery, ownership, quotas, expiry and delivery locks:

```bash
vendor/bin/phpunit -c phpunit.dist.xml --no-coverage \
    tests/Feature/ExportsTest.php tests/Feature/TaxonSearchTest.php
```

The opt-in MySQL-compatible suite installs randomly prefixed test tables only in an isolated
database whose name ends in `_exports_validation`. Never point it at a production database.
Use a dedicated test user and configure access through environment variables:

```bash
TANHUB_EXPORT_TEST_DATABASE=tanhub_exports_validation \
TANHUB_EXPORT_TEST_HOST=127.0.0.1 \
TANHUB_EXPORT_TEST_USER=test_user \
vendor/bin/phpunit -c phpunit.dist.xml --no-coverage tests/Integration/ExportMySqlTest.php
```

`TANHUB_EXPORT_TEST_PASSWORD` and `TANHUB_EXPORT_TEST_PORT` configure authentication/port.
The host can also be an absolute local socket path. The suite checks every taxon sort against
database-native case/accent ordering.

Set `TANHUB_EXPORT_SCALE_ROWS=2000000` to enable the large occurrence fixture. It verifies every
output row against an independent source traversal after repeated capture/writing requests,
enforces a five-second processing-request budget and a 128 MiB peak PHP-memory ceiling, and
reports measured maximum batch duration. Expect substantial disposable database storage.
The test deletes its own prefixed tables/files afterwards. It does not certify every host or
every filtered query; inspect representative query plans and test your intended deployment.

## Run only taxon media focused tests

Use these during media upload and include refactors:

```bash
vendor/bin/phpunit -c phpunit.dist.xml tests/unit/Services/TaxonMediaUploadServiceTest.php
vendor/bin/phpunit -c phpunit.dist.xml tests/Feature/TaxonMediaFilesDeliveryTest.php
vendor/bin/phpunit -c phpunit.dist.xml tests/Feature/TaxaPagesTest.php --filter 'testUploadMedia|testDetailsShowsSeededTaxonMediaCard'
vendor/bin/phpunit -c phpunit.dist.xml tests/Feature/ApiV1LookupResourcesTest.php --filter 'testTaxaIncludeTaxonMedia|testTaxonNamesIncludeTaxonMedia|testOccurrencesIncludeTaxonMedia|testTaxonStatsIncludeTaxonMedia|testTaxonYearStatsIncludeTaxonMedia'
```

## Run only taxon-stats related tests

Use PHPUnit `--filter` to run a narrow set while refactoring.

```bash
vendor/bin/phpunit -c phpunit.dist.xml tests/Feature/ApiV1LookupResourcesTest.php --filter testTaxonStats
```

Run taxon-year-stats tests:

```bash
vendor/bin/phpunit -c phpunit.dist.xml tests/Feature/ApiV1LookupResourcesTest.php --filter testTaxonYearStats
```

Run both in one command:

```bash
vendor/bin/phpunit -c phpunit.dist.xml tests/Feature/ApiV1LookupResourcesTest.php --filter 'testTaxon(Year)?Stats'
```

## Recommended refactor loop

1. Run the focused tests for the code you will change.
2. Make the smallest coherent change.
3. Re-run the same focused tests; a passing result confirms the local behavior.
4. Run the full lookup test file if the API contract is involved.
5. Run `composer test` before committing.

Example sequence:

```bash
vendor/bin/phpunit -c phpunit.dist.xml tests/Feature/ApiV1LookupResourcesTest.php --filter 'testTaxon(Year)?Stats'
vendor/bin/phpunit -c phpunit.dist.xml tests/Feature/ApiV1LookupResourcesTest.php
composer test
```

Media-specific sequence:

```bash
vendor/bin/phpunit -c phpunit.dist.xml tests/unit/Services/TaxonMediaUploadServiceTest.php
vendor/bin/phpunit -c phpunit.dist.xml tests/Feature/TaxonMediaFilesDeliveryTest.php
vendor/bin/phpunit -c phpunit.dist.xml tests/Feature/ApiV1LookupResourcesTest.php --filter 'TaxonMedia'
composer test
```

## Coverage and logs

`phpunit.dist.xml` is configured to write:

- JUnit XML: `build/logs/logfile.xml`
- Testdox text: `build/logs/testdox.txt`
- Testdox HTML: `build/logs/testdox.html`
- Coverage HTML: `build/logs/html`
- Coverage Clover XML: `build/logs/clover.xml`

`composer test` already runs PHPUnit with `XDEBUG_MODE=coverage`.

If your machine does not have Xdebug enabled yet, install and enable it for your
active PHP CLI runtime first.

Example:

```bash
pecl install xdebug
php -m | grep -i xdebug
```

Then run:

```bash
composer test
```

## Notes for endpoint refactors

- Keep response envelope shape stable (`data`, `meta`, `links`) to avoid breaking existing API
    tests.
- If you add new filterable or sortable fields, add matching feature tests in
    `tests/Feature/ApiV1LookupResourcesTest.php`.
- If response fields change intentionally, update both tests and API documentation together.
- Taxonomy changes must keep `docs/database.md`, `docs/import.md`, `docs/api.md`,
  `docs/admin-ui.md`, and `docs/openapi.v1.yaml` consistent with the migration and feature tests.

## See also

- [Architecture](architecture.md)
- [API reference](api.md)
