# tanhub

## About

Tanhub is a wildlife observation management platform that aggregates and organizes wildlife data
from multiple sources into a centralized database. It provides a RESTful API that enables users to
query, analyze, and visualize ecological observation data for research, reporting, and conservation
purposes. Tanhub uses technology commonly found on shared web-servers so is easy to install and
low-cost to run.

## Documentation

See [docs/README.md](docs/README.md) for the full documentation index, covering installation,
architecture, the database schema, the API, administration, and testing.

## Installation

See [docs/installation.md](docs/installation.md) for installation notes.

## Frontend styling

The project uses SCSS sources in `assets/scss` and compiles them into a single output file,
`public/css/site.css`.

- Install frontend build dependencies: `npm install`
- Build styles once: `npm run css:build`
- Watch and rebuild while editing: `npm run css:watch`

For full styling conventions and file organisation, see
[docs/frontend-styling.md](docs/frontend-styling.md).

## Server Requirements

PHP version 8.2 or higher is required, with the following extensions installed:

- [intl](http://php.net/manual/en/intl.requirements.php)
- [mbstring](http://php.net/manual/en/mbstring.installation.php)

Additionally, make sure that the following extensions are enabled in your PHP:

- json (enabled by default - don't turn it off)
- [mysqlnd](http://php.net/manual/en/mysqlnd.install.php)
- [libcurl](http://php.net/manual/en/curl.requirements.php).

MySQL version 5.7 or higher (or a compatible equivalent) is required for data storage.