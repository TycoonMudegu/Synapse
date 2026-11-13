# PHP deployment

1. Provision PHP 8.2+, the listed extensions, MySQL 8+/MariaDB 10.6+, HTTPS, cron and a process supervisor.
2. Install Composer production dependencies with `composer install --no-dev --classmap-authoritative`. Build assets using `npm ci && npm run build` during release preparation. Node is not needed for PHP requests.
3. Configure a private `.env` or service environment with a persistent APP_KEY, exact HTTPS APP_URL, secure cookies and a dedicated database account. Back up before migrations.
4. Run `php scripts/migrate.php`, then `php scripts/create-admin.php` on first installation only. Migration DDL auto-commits in MySQL; recover a partially failed migration from a backup before retrying.
5. Set the web root to `Public/` and give only the service account access to `.env` and private storage. Use the Nginx or Apache configuration as a starting point. PHP and worker processes need access to the same private storage.
6. Configure the cron entry and worker service from `deploy/`. Run both under a restricted account, not root. Match PHP memory/time/upload limits to allowed document sizes. The worker needs outbound HTTPS access and database permissions.
7. Configure and test AI, Gmail and sources in the UI. Keep scheduling disabled until those checks succeed.
8. Verify sign-in, upload/download, manual job/application flow, real worker activity and connection tests. Verify backups and restoration of credentials with the same APP_KEY.

Do not use PHP's built-in development server for production. Static hosting and the Sites Cloudflare Worker runtime cannot run PHP/PDO MySQL; this repository has intentionally not been converted to another framework or deployed as a fake static preview.

The included local `.env` points at an isolated `/tmp` development database and must be replaced for production. Do not expose that database/socket or test server publicly.
