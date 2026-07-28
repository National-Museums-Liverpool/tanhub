# Troubleshooting

This guide covers known configuration and operational checks. It does not
describe fixes for unresolved import defects.

## The application exposes detailed errors

Set the following in `.env` on production systems:

```dotenv
CI_ENVIRONMENT = production
```

Development error pages can reveal configuration and stack details. See
[Installation](installation.md#2-application-setup) for the full production
setup sequence.

## The database has not been created or updated

Confirm the `database.default.*` settings in `.env` point to the intended MySQL
database, then visit `/update` and run the migration action. Create the initial
administrator through `/setup-admin-user` after the database setup is complete.

See [Installation](installation.md#2-application-setup) for the prerequisites
and required database permissions.

## Taxon media uploads fail

Ensure that `writable/uploads/taxon-media` exists and the web server user can
write to `writable/uploads`. Check the configured maximum upload size and
allowed MIME types if the file is larger than the accepted limit or uses an
unsupported format.

See [Configuration reference](configuration-reference.md#taxon-media) and
[Administration](administration.md) for media settings and variant maintenance.

## An import task is blocked

The Imports page shows `Blocked by ...` when another required task has not
completed. Run the listed prerequisite tasks until their offset is complete,
then return to the dependent task. Queued tasks run in the order added; a task
that does not complete does not unblock its dependants.

See [Import](import.md#running-the-imports-using-the-admin-user-interface) for
task categories and the command-line alternatives.

## Indicia imports cannot connect

Check `Config\Import.indiciaWarehouseUrl`, `Config\Import.indiciaProjId`,
`Config\Import.indiciaUsername`, and `Config\Import.indiciaSecret`. Confirm
the corresponding REST API client, connection, and permitted reports exist in
the Indicia warehouse.

The full warehouse-side setup is documented in
[Installation](installation.md#4-link-tanhub-to-an-indicia-warehouse).

## A browser API request is blocked by CORS

Add the calling application's exact origin to `CORS_ALLOWED_ORIGINS`, or add a
carefully scoped expression to `CORS_ALLOWED_ORIGINS_PATTERNS`. Confirm that
`CORS_ALLOWED_HEADERS` includes `Authorization` for Bearer-token requests.

See [Configuration reference](configuration-reference.md#cross-origin-requests)
and [Installation](installation.md#3-api-configuration).

## An API request is rejected or throttled

For `401` responses, obtain a token from `POST /api/v1/auth/token` using the
account email address in the `username` field, then send it as a Bearer token.
For `429` responses, wait for the `Retry-After` value before retrying. Review
the configured anonymous and authenticated limits if the expected traffic is
being throttled.

See [API reference](api.md#21-jwt-endpoints) and
[Configuration reference](configuration-reference.md#api-access).