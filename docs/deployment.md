# Production deployment

Synapse is prepared for `https://jobflow.mystuffs.co.ke`. The hosting diagnostic page confirmed its filesystem document root as `/home/themude3/domains/jobflow.mystuffs.co.ke/public_html/`. The FTP path must still be verified from the FTP account: an account confined to this folder may see `/`, while an account rooted at the home directory may see `/domains/jobflow.mystuffs.co.ke/public_html/`. Never copy Hummingbird's FTP destination or database credentials.

## GitHub Actions

[The deployment workflow](../.github/workflows/deploy.yml) deploys pushes to `main`, and also supports manual runs. Its verification job runs PHP integration, migration and deployment transport checks on both MySQL 8.4 and MariaDB 10.11, plus browser checks on MySQL. Application upload starts only after production migration history and the required schema have been verified.

Create the `production` GitHub environment and configure these settings there (repository settings also work when no environment value overrides them):

| Kind | Name | Value |
| --- | --- | --- |
| Variable | `DB_DEPLOY_BASE_URL` | `https://jobflow.mystuffs.co.ke` (the workflow default) |
| Variable | `FTP_SERVER_DIR` | Exact FTP-visible web-root folder, beginning and ending with `/` |
| Variable | `FTP_PROTOCOL` | `ftps` by default; use the protocol supported by the hosting account |
| Variable | `FTP_PORT` | `21` by default |
| Secret | `FTP_SERVER` | Synapse hosting FTP hostname |
| Secret | `FTP_USERNAME` | FTP account with access to this site's web root |
| Secret | `FTP_PASSWORD` | FTP password |
| Secret | `DB_DEPLOY_SECRET` | 64 lowercase hex characters, matching the private hosting `.env` |

The deployment keys are also listed in `.env.example` and the private local `.env` for setup. The example keeps credential values empty. Actions reads its own configured secrets and variables, so editing a local env file does not update GitHub or hosting. If `DB_DEPLOY_SECRET` already exists in GitHub, reuse that exact value in private `.env` and the hosting `.env`; do not generate a different value for either file. GitHub cannot return the saved secret value. If it is no longer available, generate a replacement privately and update all three locations together.

The workflow installs locked Composer dependencies, prepares PDF.js and Tailwind assets, and builds two upload packages. The application package uses an explicit list of runtime paths; it excludes local `.env`, Google credential JSON files, private storage, email receipts, tests, Node modules and Git metadata. The migration package contains only the SQL migration files and its small authenticated PHP runtime. Both packages are assembled locally under ignored directories, `.release/` and `.migration-upload/`.

The first upload installs access rules and empty private runtime directories. A read-only HTTPS `HEAD /.env` check must return access denied. A release-specific migration endpoint then verifies authentication, the package manifest and every file hash before connecting to the database. SQL changes run before the application FTP upload. The final checks request the login page, compiled CSS and PDF worker with the expected JavaScript MIME type. Uploaded user files and the hosting environment are kept outside the application upload's managed file set.

## One-time hosting setup

1. Enable PHP 8.2+ with curl, DOM, fileinfo, mbstring, OpenSSL, PDO MySQL, sodium and zip. Enable Apache rewrites and `.htaccess` overrides, or configure equivalent rules in the actual web server. Keep HTTPS active. This FTP workflow uses the **project root** as the document root and relies on the shipped root `.htaccess`; do not change it to `Public/` while using this workflow.
2. Create a dedicated production database and account with the DDL and data permissions needed by Synapse. Start with an empty database for a new installation. For an existing Synapse database, preserve its `migrations` history and take a verified backup. Do not copy Hummingbird's database or replay old SQL by hand. Database setup and recovery are described in [database/README.md](../database/README.md).
3. Create `.env` privately in the hosting document root using [.env.example](../.env.example). Set `APP_ENV=production`, `APP_URL=https://jobflow.mystuffs.co.ke`, `SESSION_SECURE=true`, the real database connection, and a durable `APP_KEY`. Generate a fresh application key for a fresh database; keep the existing key when restoring encrypted settings from an existing database. Never put these values in Git. Give only the PHP/cron account read access.
4. Generate a distinct deployment secret privately with `php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'`. Put the same value in hosting `DB_DEPLOY_SECRET` and the GitHub Actions secret. Confirm the host's backup schedule, set `DB_DEPLOY_BACKUP_POLICY=hosting-daily`, `DB_DEPLOY_BACKUP_UTC=HH:MM` in UTC, and `DB_DEPLOY_BACKUP_MAX_AGE_HOURS=4` (or the chosen 1–12 hour interval). A fresh, empty database may install before a backup schedule is available; later schema changes require it.
5. Store the Google service-account JSON in a private folder **outside** `public_html`, with its absolute path in `GOOGLE_APPLICATION_CREDENTIALS`. Set the project, private bucket and `DOCUMENT_STORAGE=gcs`. Keep the renderer's existing Cloud Run URL and shared token private in `DOCUMENT_RENDERER_URL` and `DOCUMENT_RENDERER_TOKEN`. The renderer is already deployed and has been tested from this workspace; it is maintained separately in `../Synapse-document-renderer`.
6. Register `https://jobflow.mystuffs.co.ke/Callback` in the Google OAuth web client for both sign-in and optional Gmail access. Set both callback environment values to that URL. Configure private Gemini and SMTP settings as required. Enable real M-Pesa payments only after setting production Daraja credentials and `https://jobflow.mystuffs.co.ke/api/billing/mpesa/callback`.
7. The document bucket now allows `https://jobflow.mystuffs.co.ke` for browser uploads and previews; its existing CORS rules were preserved. For later configuration changes, add any additional production origins to the bucket's browser CORS rules. Preview the configuration using `php scripts/configure-cloud-storage.php --origin=https://jobflow.mystuffs.co.ke`, then apply it with `--apply`. Include any other active origins in `GCS_CORS_ORIGINS` when running this command: it replaces the bucket's CORS rule rather than merging the current rule. The bucket must retain uniform access and public access prevention.
8. Set storage directory permissions so the PHP account can write `storage/private`, `storage/logs`, `storage/sessions` and `storage/run`. Install the minute cron below using the hosting account's PHP CLI executable. It schedules due work and processes the full queue, including email alerts and document retention, with a lock to prevent overlapping runs. Do not run a second scheduler for the same installation.

```cron
* * * * * /usr/bin/php /home/themude3/domains/jobflow.mystuffs.co.ke/public_html/scripts/cron.php >> /home/themude3/domains/jobflow.mystuffs.co.ke/public_html/storage/logs/cron.log 2>&1
```

Confirm `/usr/bin/php` is the hosting account's correct PHP 8.2+ CLI binary. Uploads, temporary document processing, outbound HTTPS and the chosen SMTP port must be permitted by hosting. Keep `APP_KEY`, the environment and database backups together in private backup storage. Leave user automation paused until production connections have been checked.

## First release and updates

Once hosting setup and GitHub settings are present, push the verified source to `main` or run **Deploy Synapse** manually. Review the Actions summary for migration history, application upload and public checks. A green migration step by itself does not mean the whole application deployed.

After the first release, register an account and verify sign-in, document upload/download, a real PDF preview, the job/application review flow and cron processing. Confirm Google callbacks and the renderer connection on the production origin. Email tests must use an explicitly chosen recipient. Automatic job submissions remain subject to each account's own review and mode settings.

Subsequent releases use the same workflow. SQL migrations are immutable once applied. Add new numbered SQL files and update `database/schema.php`; the workflow preserves existing user records. An interrupted DDL statement stops the deployment for review. The manual **retry_failed** input can reconcile supported additive DDL after inspection; it does not blindly replay uncertain data writes. The emergency backup-window override requires a written reason and only bypasses a correctly configured schedule's time window. It does not create a backup or bypass failed-migration review.

For manual deployment from an already prepared release, upload `.release/bootstrap/`, run the database CLI on hosting, then upload `.release/application/`. Keep `.env` and credentials private and preserve `storage/`. Normal updates should use Actions so the migration and code order is consistent.

## Assets, worker and optional browser collection

Prepare assets using `npm ci`, `npm run build:pdf` and `npm run build:css` during release preparation. Node is a build dependency, not a PHP web-server requirement. Deploy all of `Public/Assets/`, including the pinned PDF.js 6.5.438 modules, worker, character maps, fonts, WebAssembly files and licence. Serve `.mjs` files as JavaScript and retain the shipped `worker-src 'self'` policy. The reader fetches document bytes directly from private Google Cloud Storage after an authenticated viewing redirect.

The minute cron works without a process supervisor. On a VPS, a supervised worker from `deploy/` can replace the bounded shared-hosting worker; run it under the same account as PHP and configure only one scheduler. Restart persistent workers after changing their environment.

Fuzu collection additionally needs Node, the production `playwright` package, Chromium and its system libraries under the worker account. It defaults to a visible browser and requires a desktop or virtual display. Those dependencies are not included in the PHP FTP package; enable Fuzu only when the host supports them, or use a suitable separate worker host. Standard PHP collectors do not require Chromium. See [integration setup](integrations.md#built-in-job-sources).

Do not use PHP's built-in development server for production. Static hosting cannot run this PHP/MySQL application.

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

Existing private runtime files may also need a targeted ACL for the service account. Run the worker and scheduled tasks under the **same account as Apache**, for example `sudo -u www-data php scripts/worker.php`, so uploaded/generated private documents remain readable by their owner. Apache serves the app directly; run its worker under the same service account.

On systems where Apache has `PrivateTmp=true`, a database socket in `/tmp` is invisible to Apache. Use the normal database service socket (for example `/run/mysqld/mysqld.sock`), a configured localhost database connection, or a protected project runtime socket. Temporary test database sockets must not replace the normal application database configuration.

The rewrite behaviour follows [Apache's per-directory END flag](https://httpd.apache.org/docs/2.4/rewrite/flags.html#flag_end); override requirements are described in [Apache's .htaccess guide](https://httpd.apache.org/docs/2.4/howto/htaccess.html).

For an isolated Apache server configured to use `jobflow_test`, exercise the same browser workflow under a subfolder with:

```bash
JOBFLOW_BROWSER_BASE_URL=http://127.0.0.1:8849/autoaplicator node tests/browser.mjs
```

That test override must point at a test server using the isolated test database, never the normal application database. The browser runner creates and removes test-only users.
