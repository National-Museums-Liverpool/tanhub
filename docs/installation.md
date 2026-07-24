## Installation Guide

This guide is intended for a first-time tanhub setup. It covers:

- local application setup
- first-run database setup
- optional API throttling configuration
- linking tanhub to an Indicia warehouse

## 1. Prerequisites

Before installing, ensure you have:

- Composer (https://getcomposer.org/doc/00-intro.md#installation-linux-unix-macos)
- Git (optional) - (https://git-scm.com/install/)
- a web server (or local dev stack) with PHP 8.2 or higher and MySQL
- PHP GD extension enabled for image resizing and variant generation
- access to an Indicia warehouse with rights to configure REST API connections. The warehouse
  should have a taxon list populated with the contents of the UKSI species list as well as
  occurrence data that you will import into tanhub.

## 2. Application Setup

1. Obtain a copy of the code using Git as follows:

   ```bash
   git clone https://github.com/National-Museums-Liverpool/tanhub.git
   cd tanhub
   ```

   If you don't have Git installed you can obtain the code manually by visiting
   [Github](https://github.com/National-Museums-Liverpool/tanhub/tree/master) and clicking Code,
   then Download Zip. Save the file, then unzip it and copy the `tanhub-master` contents to a
   folder where you want the installation to run from and rename it to `tanhub`.

2. Install dependencies:

   ```bash
   composer install --no-dev
   ```

3. Configure your web server to use `tanhub/public` as the document root.

4. Create a MySQL database (for example, `tanhub`) and a database username and password which has
   full access to the database you created.

5. Copy the supplied local environment configuration:

```bash
cp env .env
```

6. Edit `.env` and set at least:

   - `database.default.database` to the name of the MySQL database you created.
   - `database.default.username` to the name of the MySQL user you created.
   - `database.default.password` to the password of the MySQL user you created.
   - `Config\Email.fromEmail` to the email address emails (such as lost password reset emails) will
      be sent from.
   - `Config\Email.fromName` to the name emails (such as lost password reset emails) will be sent
      from.

   Make sure you remove the # from the start of any line you edit so that it is not commented out.
   You may have to also alter other settings for the `database.default` configuration if not using
   a default local MySQL database server setup.

7. Set up import-related configuration in `.env`:

   - `import.taxonRanks` - the list of taxon ranks you would like to be able to view and report
     against. A comma-separated list, where each rank matches one of the ranks used in the UKSI
     database. Ranks not in the list will be ignored during import. This must always include
     "Species".
   - `import.taxonGroups` - the list of taxon group names to include when importing occurrence
      data. Should align with UKSI group names. Note that other group names may also be imported
      into the taxon_groups table if required to complete the taxonomic hierarchy for imported
      taxa (a higher taxon may have a group called "unassigned" for example).
   - `import.geographicRegions` - set to the names of the regions you want to include in tanhub.
      These should be indexed locations in the Indicia warehouse.
   - `import.geographicRegionLocationType` - set to the name of the Location Type in Indicia that
     the regions belong to, for example "Vice County".

8. Visit the `/update` page in your browser and click the button to run the migration scripts,
   which set up the database.

9. Visit the `/setup-admin-user` and follow the instructions to create the first admin account.

   - This is the only self-service account creation step.
   - After setup, open `/users` as an admin to create and manage all other users.
   - Public `/register` self-registration is disabled.

10. For production environments, enable production mode in `.env`. Note that this step is important
    as without it, full stack dumps are shown on errors which may contain credentials:

```dotenv
CI_ENVIRONMENT = production
```

11. Ensure writable media directories exist and are writable by the web server user:

```bash
mkdir -p writable/uploads/taxon-media
chmod -R ug+rwX writable/uploads
```

12. Optional taxon media configuration in `.env`:

```dotenv
taxonMedia.uploadSubdirectory = taxon-media
taxonMedia.maxUploadBytes = 10485760
taxonMedia.allowedMimeTypes = image/jpeg,image/png,image/gif,image/webp
taxonMedia.variants.thumbnail.width = 320
taxonMedia.variants.thumbnail.height = 320
taxonMedia.variants.thumbnail.mode = fit
taxonMedia.variants.thumbnail.quality = 85
taxonMedia.variants.large.width = 1400
taxonMedia.variants.large.height = 1400
taxonMedia.variants.large.mode = contain
taxonMedia.variants.large.quality = 90
```

## 3. API Configuration

If tanhub is configured to serve only publicly viewable data, API access can be allowed without
authentication, in which case rate limits are applied to prevent misuse or denial-of-service
attacks. Tanhub supports separate throttle windows for anonymous and authenticated requests.
Add or override these keys in `.env` as required:

```dotenv
api.rateLimitAnonymousCapacity = 20
api.rateLimitAnonymousSeconds = 20
api.rateLimitAuthenticatedCapacity = 60
api.rateLimitAuthenticatedSeconds = 20
```

- `api.rateLimitAnonymousCapacity`: anonymous requests allowed per window
- `api.rateLimitAnonymousSeconds`: anonymous window duration in seconds
- `api.rateLimitAuthenticatedCapacity`: authenticated requests allowed per window
- `api.rateLimitAuthenticatedSeconds`: authenticated window duration in seconds

In order to allow access from JavaScript running in a browser, you need to configure the allowed
origins by adding the following to .env, with a list of allowed domains:

```dotenv
CORS_ALLOWED_ORIGINS=http://localhost:3000,https://app.example.com
```

You can also use a pattern match to enable access for a range of similar domains:

```dotenv
CORS_ALLOWED_ORIGINS_PATTERNS=^https://.*\.staging\.example\.com$
```

Further CORS configuration options are available as in the following examples if required:

```dotenv
CORS_SUPPORTS_CREDENTIALS=true
CORS_ALLOWED_HEADERS=Origin,Content-Type,Accept,Authorization,X-Requested-With
```

## 4. Link tanhub to an Indicia Warehouse

1. In the warehouse, open `Admin > REST API Clients` from the menu. If you don't have privileges to
   see this menu item then you will have to request that the warehouse administrator does this for
   you.
2. Create a REST API client:
   - Title: `tanhub`
   - Username: `tanhub`
   - Website: select the source website which holds the records you want to be able to import.
     Other websites which share their data for reporting to the chosen website will also be
     included.
   - Secret: enter a strong secret and keep a copy of it securely.
3. Save, then open the new client and go to the `Connections` tab.
4. Create a new client connection with:
   - Title: `tanhub`
   - Proj ID: `TANHUB`
   - Sharing mode: `Reporting`
   - Filter: you can optionally point this to a filter saved in the Indicia warehouse which
     enforces access to the correct occurrence data. The tanhub import routine will apply its own
     filtering so this option is only required if access to disallowed data is a concern.
   - Allow confidential records: unticked
   - Allow sensitive records: ticked
   - Allow unreleased records: unticked
5. Set the Elasticsearch endpoint to the endpoint name that exposes all records as configured in
   the REST API.
6. In `allow reports`, add:

   ```text
   projects/tanhub/grid_square_stats.xml
   projects/tanhub/geographic_regions.xml
   projects/tanhub/recording_schemes.xml
   projects/tanhub/taxa.xml
   projects/tanhub/taxon_groups.xml
   projects/tanhub/taxon_names.xml
   projects/tanhub/taxon_ranks.xml
   ```

7. Tick "Allow sensitive records" if you want sensitive records at blurred precision. Do not
   tick "Allow confidential records" or "Full precision sensitive records" as the API for tanhub
   is public.
8. Save the connection.
9. In tanhub `.env`, configure the Import section to match the connection settings as follows:
  - `Config\Import.indiciaWarehouseUrl` - the warehouse URL without trailing slash or `index.php`.
  - `Config\Import.indiciaTaxonListId` - the taxon list on the warehouse which contains the UKSI
   data.
  - `Config\Import.indiciaProjId` - set to `TANHUB` (or the Proj ID set for your client connection if different).
  - `Config\Import.indiciaUsername` - set to `tanhub` (or the username set for your API client if different).
  - `Config\Import.indiciaSecret` - set to the secret given for your API client.
  - `Config\Import.indiciaOccurrencesEsEndpoint` - match the endpoint from step 5.

## 5. Prepare the Indicia Warehouse

1. Ensure required tanhub reports are present in the warehouse.
2. In pgAdmin, grant read access for reporting:

```sql
grant select on recording_scheme_taxa to indicia_report_user;
grant select on recording_schemes to indicia_report_user;
```

## 6. Next Step

After installation and warehouse linkage, continue with the import process in [docs/import.md](docs/import.md).