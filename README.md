# tanhub

## About

Tanhub is a wildlife observation management platform that aggregates and organizes wildlife data from multiple sources into a centralized database. It provides a RESTful API that enables users to query, analyze, and visualize ecological observation data for research, reporting, and conservation purposes. Tanhub uses technology commonly found on shared web-servers so is easy to install and low-cost to run.

## Installation

See [docs/installation.md](docs/installation.md) for installation notes.

## Server Requirements

PHP version 8.2 or higher is required, with the following extensions installed:

- [intl](http://php.net/manual/en/intl.requirements.php)
- [mbstring](http://php.net/manual/en/mbstring.installation.php)

Additionally, make sure that the following extensions are enabled in your PHP:

- json (enabled by default - don't turn it off)
- [mysqlnd](http://php.net/manual/en/mysqlnd.install.php)
- [libcurl](http://php.net/manual/en/curl.requirements.php).

MySQL version 5.7 or higher (or a compatible equivalent) is required for data storage.