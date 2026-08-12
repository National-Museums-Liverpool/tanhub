# Testing guide

This guide explains how to run tests safely before and after refactoring API endpoints, with a focus on `taxon-stats` and `taxon-year-stats`.

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

## Run all unit/feature tests

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

## Suggested safe refactor loop

1. Run the focused tests for taxon stats before making changes.
2. Refactor endpoint code.
3. Re-run the focused tests.
4. Run the full lookup test file.
5. Run the full suite (`composer test`) before committing.

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

- Keep response envelope shape stable (`data`, `meta`, `links`) to avoid breaking existing API tests.
- If you add new filterable or sortable fields, add matching feature tests in `tests/Feature/ApiV1LookupResourcesTest.php`.
- If response fields change intentionally, update both tests and API documentation together.
- Taxonomy changes must keep `docs/database.md`, `docs/import.md`, `docs/api.md`,
  `docs/admin-ui.md`, and `docs/openapi.v1.yaml` consistent with the migration and feature tests.

## See also

- [Architecture](architecture.md)
- [API reference](api.md)
