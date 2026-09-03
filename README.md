# tanhub

## About

Tanhub is a wildlife observation platform for bringing biodiversity data together and making it
useful. It imports records from multiple sources into a centralised database, where they can be
managed by staff and used to produce reports, species accounts, atlases, and other public-facing
tools.

If you work with ecological data, run a local or regional recording scheme, or build applications
for research and conservation, tanhub gives you a practical foundation for storing and sharing
observation data. Its read-only REST API makes it straightforward to query, analyse, and visualise
that data, while its shared-server-friendly technology keeps installation and hosting costs low.

[tanvis](https://github.com/National-Museums-Liverpool/tanvis) is a natural companion for building
visualisations from data held on a [tanhub server](https://github.com/National-Museums-Liverpool/tanhub).

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