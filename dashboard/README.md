# Print Tracker Dashboard

The dashboard is a PHP web application that displays print and activity reports from the Print Tracker MySQL database. It is read-only and does not require Node.js to run; Chart.js and jsPDF are included in `assets/vendor/`.

## Requirements

- PHP 8.1 or later with `pdo_mysql` and session support enabled
- MySQL with the Print Tracker tables installed
- A PHP-enabled web server such as Apache for a hosted deployment

## Install

1. Create the database tables by running [`../database.sql`](../database.sql) in MySQL Workbench or the MySQL client. If upgrading an existing database, back it up and follow [`../migrate_normalize_devices.sql`](../migrate_normalize_devices.sql) instead; do not run that one-time migration more than once.
2. Create a dedicated MySQL account for the dashboard and grant it read-only access:

   ```sql
   CREATE USER 'print_tracker_reporter'@'dashboard_host'
       IDENTIFIED BY 'use-a-unique-password';
   GRANT SELECT ON print_tracking_db.* TO 'print_tracker_reporter'@'dashboard_host';
   ```

   Replace `dashboard_host` with the PHP server's host or IP. Use a database account with only `SELECT` privileges.
3. Copy the `dashboard/` directory to the PHP server's document root. Keep `assets/vendor/` and `assets/dashboard.js` with the PHP files; do not upload `node_modules/`.
4. Configure the dashboard using one of the following methods:
   - **Local web installer:** start the PHP built-in server from the project root with `php -S 127.0.0.1:8080 -t dashboard`, then open `http://127.0.0.1:8080/install.php`. Enter the MySQL account details and create a dashboard login. The web server must be able to write to the `dashboard/` directory while installing.
   - **Hosted web installer:** enable HTTPS and set a server-side `PRINT_TRACKER_INSTALL_TOKEN` of at least 32 characters. Generate one with `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`. Open `https://your-dashboard-host/install.php` and enter the same token in the installer. Do not expose the installer over HTTP.
   - **SSH installer:** if the host does not have HTTPS, upload `install_cli.php`, connect to the server over SSH, change to the dashboard directory, and run `php install_cli.php`. Enter the credentials at the terminal prompts.
5. After installation succeeds, delete `install.php` from the deployed dashboard directory. If you used the hosted installer, remove `PRINT_TRACKER_INSTALL_TOKEN` from the server environment.
6. Open the dashboard URL and sign in with the dashboard username and password created during installation.

The installer verifies the database connection and required tables, then writes `config.local.php` with the database credentials and a hash of the dashboard password. Do not commit or publish that file. The supplied `.htaccess` denies web access to local configuration files on Apache; configure equivalent access restrictions on other web servers.

For a local development server, PHP can be started from the project root with:

```powershell
php -S 127.0.0.1:8080 -t dashboard
```

Then browse to `http://127.0.0.1:8080`. Set `APP_TIMEZONE` to the timezone used by the computers recording data if the report date defaults should match their local timestamps. Use HTTPS when hosting beyond localhost.

## Refresh browser libraries

This is optional and is only needed when updating the vendored JavaScript libraries. From `dashboard/`, run:

```powershell
npm install
npm run vendor
```

Commit the updated files in `assets/vendor/`, not `node_modules/`.
