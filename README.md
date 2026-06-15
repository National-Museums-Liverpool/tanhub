# TanHub

## About

## Installation

1. Grab the code from GitHub.
2. $ composer install --no-dev
3. Copy env to .env and set:
  * Config\Email.fromEmail
  * Config\Email.fromName
  * Settings for database.default
4. Visit /update to run the database migrations and set up the initial database.
5. Visit /setup-admin-user to define an admin user.
6. Uncomment this line in your .env file if this is a production server by removing the # at the start:
   ```
   # CI_ENVIRONMENT = production
   ```

## Server Requirements

PHP version 8.2 or higher is required, with the following extensions installed:

- [intl](http://php.net/manual/en/intl.requirements.php)
- [mbstring](http://php.net/manual/en/mbstring.installation.php)

> [!WARNING]
> - The end of life date for PHP 7.4 was November 28, 2022.
> - The end of life date for PHP 8.0 was November 26, 2023.
> - The end of life date for PHP 8.1 was December 31, 2025.
> - If you are still using below PHP 8.2, you should upgrade immediately.
> - The end of life date for PHP 8.2 will be December 31, 2026.

Additionally, make sure that the following extensions are enabled in your PHP:

- json (enabled by default - don't turn it off)
- [mysqlnd](http://php.net/manual/en/mysqlnd.install.php) if you plan to use MySQL
- [libcurl](http://php.net/manual/en/curl.requirements.php) if you plan to use the HTTP\CURLRequest library
