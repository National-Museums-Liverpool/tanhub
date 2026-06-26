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
4. Copy env to .env and set:
  * Config\Email.fromEmail
  * Config\Email.fromName
  * Settings for database.default
5. Visit /update to run the database migrations and seed baseline lookup data.
6. Visit /setup-admin-user to define an admin user.
7. Uncomment this line in your .env file if this is a production server by
   removing the # at the start:
   ```
   # CI_ENVIRONMENT = production
   ```