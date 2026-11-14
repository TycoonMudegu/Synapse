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


## Apache subfolder development

The root entry point supports a project served as `http://localhost/autoaplicator/`; production can continue serving `Public/` on a dedicated hostname. Keep the root `.htaccess` active: it disables listings, routes application URLs and denies direct access to private files and directories. The Public directory also rejects direct project-tree requests, while internal asset rewrites continue to work.

A local parent directory configuration can use:

```apache
<Directory /home/your-user/projects>
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
```

Enable `mod_rewrite` and reload Apache only if changing its configuration. Set `APP_URL` to the full installation URL, including its subfolder. URL prefixes are detected from the entry point, so both Apache installations and the PHP development server continue to work. `APP_URL` also provides the installation path for CLI work.

If Apache runs as `www-data` while the checkout belongs to your own user, grant that account access using filesystem ACLs (Ubuntu's `acl` package provides `setfacl`):

```bash
setfacl -m u:www-data:r .env
setfacl -m u:www-data:rwx storage/private storage/logs storage/sessions
setfacl -m d:u:www-data:rwx storage/private storage/logs storage/sessions
```

Existing private runtime files may also need a targeted ACL for the service account. Run the worker and scheduled tasks under the **same account as Apache**, for example `sudo -u www-data php scripts/worker.php`, so uploaded/generated private documents remain readable by their owner. The ordinary `npm run dev` workflow uses your current user for both PHP and the CLI worker.

On systems where Apache has `PrivateTmp=true`, a database socket in `/tmp` is invisible to Apache. Use the normal database service socket (for example `/run/mysqld/mysqld.sock`), a configured localhost database connection, or a protected project runtime socket. This workspace's isolated development database uses `storage/run/mysql.sock`; the containing directory permits Apache traversal and is blocked from HTTP access. The database data remains temporary and must be replaced by a durable service for production.

The rewrite behaviour follows [Apache's per-directory END flag](https://httpd.apache.org/docs/2.4/rewrite/flags.html#flag_end); override requirements are described in [Apache's .htaccess guide](https://httpd.apache.org/docs/2.4/howto/htaccess.html).

For an isolated Apache server configured to use `jobflow_test`, exercise the same browser workflow under a subfolder with:

```bash
JOBFLOW_BROWSER_BASE_URL=http://127.0.0.1:8849/autoaplicator npm run test:browser
```

That test override must point at a test server using the isolated test database, never the normal application database. The browser runner creates and removes test-only users.
