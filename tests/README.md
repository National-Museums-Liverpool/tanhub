# Running Application Tests

This is the quick-start to CodeIgniter testing. Its intent is to describe what
it takes to set up your application and get it ready to run unit tests.
It is not intended to be a full description of the test features that you can
use to test your application. Those details can be found in the documentation.

For Tanhub-specific commands, focused test filters, and coverage output
locations, see [docs/testing.md](../docs/testing.md).

## Resources

* [CodeIgniter 4 User Guide on Testing](https://codeigniter.com/user_guide/testing/index.html)
* [PHPUnit docs](https://phpunit.de/documentation.html)
* [Any tutorials on Unit testing in CI4?](https://forum.codeigniter.com/showthread.php?tid=81830)

## Requirements

It is recommended to use the latest version of PHPUnit. At the time of this
writing, we are running version 9.x. Support for this has been built into the
**composer.json** file that ships with CodeIgniter and can easily be installed
via [Composer](https://getcomposer.org/) if you don't already have it installed globally.

```console
> composer install
```

If running under macOS or Linux, you can create a symbolic link to make running tests a touch nicer.

```console
> ln -s ./vendor/bin/phpunit ./phpunit
```

You also need to install [XDebug](https://xdebug.org/docs/install) in order
for code coverage to be calculated successfully. After installing `XDebug`, you must add `xdebug.mode=coverage` in the **php.ini** file to enable code coverage.

## Setting Up

A number of the tests use a running database.
In order to set up the database edit the details for the `tests` group in
**app/Config/Database.php** or **.env**.
Make sure that you provide a database engine that is currently running on your machine.
More details on a test database setup are in the
[Testing Your Database](https://codeigniter.com/user_guide/testing/database.html) section of the documentation.

## Running the tests

The entire test suite can be run by simply typing one command-line command from the main directory.

```console
> ./phpunit
```

If you are using Windows, use the following command.

```console
> vendor\bin\phpunit
```

You can limit tests to those within a single test directory by specifying the
directory name after phpunit.

```console
> ./phpunit app/Models
```

For this project, prefer `composer test` or `vendor/bin/phpunit -c phpunit.dist.xml`,
and the focused per-feature commands in [docs/testing.md](../docs/testing.md).

## Generating Code Coverage

This project's `phpunit.dist.xml` already configures coverage report output
locations (`build/logs/clover.xml`, `build/logs/html`, and a text summary on
stdout), so you do not need to pass custom `--coverage-text`/`--coverage-html`
paths.

See [docs/testing.md](../docs/testing.md#coverage-and-logs) for the exact
command and output locations used by this project.

## PHPUnit XML Configuration

The repository has a ``phpunit.dist.xml`` file in the project root that's used for
PHPUnit configuration. PHPUnit automatically discovers and uses it directly when
no ``phpunit.xml`` is present, which is what `composer test` relies on.

For this project, use ``phpunit.dist.xml`` directly rather than copying it to a
local ``phpunit.xml``: the configured test suite, coverage, and logging output
locations (`build/logs/...`) are the project convention and a local copy could
silently diverge from them. See [docs/testing.md](../docs/testing.md) for the
exact commands used day-to-day.

## Test Cases

Every test needs a *test case*, or class that your tests extend. CodeIgniter 4
provides one class that you may use directly:
* `CodeIgniter\Test\CIUnitTestCase`

Most of the time you will want to write your own test cases that extend `CIUnitTestCase`
to hold functions and services common to your test suites.

## Creating Tests

All tests go in the **tests/** directory. Each test file is a class that extends a
**Test Case** (see above) and contains methods for the individual tests. These method
names must start with the word "test" and should have descriptive names for precisely what
they are testing:
`testUserCanModifyFile()` `testOutputColorMatchesInput()` `testIsLoggedInFailsWithInvalidUser()`

Writing tests is an art, and there are many resources available to help learn how.
Review the links above and always pay attention to your code coverage.

### Database Tests

Tests can include migrating, seeding, and testing against a mock or live database.
Be sure to modify the test case (or create your own) to point to your seed and migrations
and include any additional steps to be run before tests in the `setUp()` method.
See [Testing Your Database](https://codeigniter.com/user_guide/testing/database.html)
for details.
