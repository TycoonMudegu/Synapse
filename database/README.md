# Synapse database changes

Synapse keeps its existing numbered SQL migrations in `database/migrations/`. It does not use Hummingbird's schema or Phinx history. The original `migrations(name, applied_at)` ledger is preserved, including databases installed with the earlier `scripts/migrate.php`.

## Commands

```bash
php scripts/db.php status --env-file=/private/path/.env
php scripts/db.php check --env-file=/private/path/.env
php scripts/db.php migrate --env-file=/private/path/.env --backup-dir=/private/backups
php scripts/db.php create AddExampleField
```

`status` is read-only and exits with 0 for current, 3 for pending, or 1 for an error. `check` compares tables, required columns and full unique-index column sets with `database/schema.php`. The contract checks schema presence; it is not a complete comparison of column types, foreign keys or stored data. `migrate` applies pending files in number order, then verifies the contract. The selected environment file supplies the database connection directly; the CLI does not load application sessions or inherit another database's process environment. Backups require an existing private writable directory and `mysqldump` (override with `--dump-bin=/path/to/mariadb-dump`).

`php scripts/migrate.php` remains compatible with the app's existing setup and tests. It uses the same runner but reads the normal application environment. Use the explicit `--env-file` CLI for operator work against a separately configured installation.

## Migration rules

- Keep the existing `001_core.sql` through `011_document_retention.sql` unchanged. Append the next numbered file; do not renumber, delete, reorder or modify an applied file.
- Add reviewed SQL and update `database/schema.php` for required tables, columns and unique indexes. The schema file contains identifiers only, never records or credentials.
- Use separate additive statements where practical. Deploy compatible additions before code that requires them. Destructive changes and data rewrites need a separate reviewed recovery plan.
- Keep each file short enough to run within the hosting PHP request timeout. The HTTPS runner applies one file per request. Do not use client-only `DELIMITER` syntax, stored routines or executable SQL comments.
- Verify a fresh install and a second no-op run. Existing databases without recognised Synapse migration history require manual review; do not stamp unrelated tables as already migrated.

`App/Database/Runner.php` takes a database advisory lock and journals each statement before execution. Two small metadata tables, `deployment_migration_files` and `deployment_migration_steps`, preserve file checksums and statement progress. Existing applied files are adopted into the checksum ledger without executing them again. A repeat deployment preserves application records and does not replay seed inserts.

MySQL and MariaDB DDL can commit before a request finishes. If a response is lost after a migration was recorded, the client reads its history and continues. An unfinished attempt stops for inspection. After checking the protected hosting log and database state, an explicit `--retry-failed` or manual workflow `retry_failed` can reconcile supported `CREATE TABLE` and `ALTER TABLE ADD` operations against existing tables, columns and indexes. An interrupted data write or unsupported ALTER is refused: restore the pre-change backup or reconcile its effects and journal deliberately before continuing. Never mark a statement complete without verifying its actual effects, and never edit a partially executed file to conceal a failure. Keep the original SQL and use a new forward migration for subsequent changes.

## HTTPS deployment

The [Actions workflow](../.github/workflows/deploy.yml) builds an isolated, release-specific migration package and uploads it before the application release. No Composer installation, shell command or arbitrary SQL is accepted over HTTP. Requests are HTTPS POSTs authenticated with a timestamped HMAC using the private `DB_DEPLOY_SECRET`. The endpoint checks the release ID, manifest digest, all packaged file hashes and access-denial rules before connecting. It also rejects SQL files outside the manifest. Ordinary application requests never run migrations.

Shared state and diagnostic logs live under `_db-deploy/`, protected by Apache rules. Keep this directory private and writable by the hosting PHP account. Do not delete it to bypass a failed migration. The endpoint reports safe schema identifiers and database error codes to Actions; detailed errors stay in the protected hosting log.

New empty databases may install without a previous backup. Once installed, pending schema changes require the configured hosting backup window:

```dotenv
DB_DEPLOY_BACKUP_POLICY=hosting-daily
DB_DEPLOY_BACKUP_UTC=HH:MM
DB_DEPLOY_BACKUP_MAX_AGE_HOURS=4
```

Replace `HH:MM` with the actual UTC time from the hosting backup configuration, and confirm successful backups in the hosting panel. This schedule is an operator-selected deployment window, not evidence that a provider backup succeeded. A normal push cannot bypass it. A manual emergency override requires a reason and is recorded privately; it bypasses only an otherwise valid schedule's time window. Failed-migration review and schema verification remain required.

## Verification

```bash
python3 -m unittest discover -s tests -p deployment.py
php tests/migrations.php
composer test
```

The migration checks create and drop only randomly named disposable databases ending in `_test`. Supply a database user allowed to create those test databases using `DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD` and optionally `DB_SOCKET`. They do not load the application's live `.env`. Application regression tests separately use `JOBFLOW_TEST_DATABASE`, which must end in `_test`. CI runs both against MySQL and MariaDB.
