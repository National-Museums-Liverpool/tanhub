## Installation

1. Grab the code from GitHub - this will create a folder called tanhub containing the project so run it from a suitable location.
   ```bash
   $ git clone https://github.com/National-Museums-Liverpool/tanhub.git
   ```
2. Build the project dependencies:
   ```bash
   $ composer install --no-dev
   ```
3. Set up your web-server or local development environment to serve the
   tanhub/public folder as a website.
4. Create a local MySQL database called tanhub.
5. Copy env to .env and set:
  * Config\Email.fromEmail
  * Config\Email.fromName
  * Settings for database.default
6. Optionally, uncomment import.taxonRanks in the env file to provide a custom
   list of ranks which you will be able to report against.
7. Visit /update to run the database migrations and seed baseline lookup data.
8. Visit /setup-admin-user to define an admin user.
9. Uncomment this line in your .env file if this is a production server by
   removing the # at the start:
   ```
   # CI_ENVIRONMENT = production
   ```

## Linking to an Indicia warehouse

1. On the warehouse, select Admin > REST API Clients from the menu.
2. Add a new client, title TanHub, username tanhub, select the main website you are fetching records for, and provide a secret.
3. Save it, then edit the client you just created and select the Connections tab. Click New Client Connection.
4. Title = TanHub, Proj ID = TANHUB, Sharing mode = Reporting, select a filter if needed.
5. Elasticsearch endpoint es.
6. Click allow reports, and paste the following in:
   ```
   projects/tanhub/taxon_ranks.xml
   projects/tanhub/taxon_groups.xml
   projects/tanhub/recording_schemes.xml
   projects/tanhub/taxa.xml
   projects/tanhub/taxon_names.xml
   ```
7. Click Save.
8. Set up the Import section in your .env file.

## Preparing an Indicia warehouse for TanHub

1. Ensure the reports are present.
2. In pgAdmin:
   ```sql
   grant select on recording_scheme_taxa to indicia_report_user;
   grant select on recording_schemes to indicia_report_user;
   ```