# Shared job collection and administration

The independently deployable collector lives in the sibling `../Synapse-job-collector` Git repository and is managed separately. Synapse owns its source configuration, run ledger and shared catalogue. The collector has no database credentials, user profiles, application documents or authority to submit applications. It authenticates to three bounded import endpoints and exits when collection finishes. An authenticated read-only status endpoint reports catalogue readiness.

## Application setup

Apply the pending migrations through the existing migration workflow before deploying the application code. Migration `012_job_collector.sql` adds an administrator flag, collector sources/runs/batches, the public catalogue and a provenance link from a user's saved job. Migration `013_enable_public_collectors.sql` enables the initial verified public connectors without overriding sources an administrator has already used or changed. Existing users default to non-admin. Grant the owner's existing account explicitly from a trusted CLI:

```bash
php scripts/admin.php grant --email=owner@example.com
```

`revoke` removes that permission. Registration, account settings and request bodies cannot grant administrator access. `/admin` and every admin API action enforce the role on the server. Ordinary authenticated users can browse `/catalogue` and save a published, unexpired vacancy to their own workspace. Copies remain unverified, and existing application approval rules apply.

To create an entirely new administrator account, run this on the intended Synapse host after applying the migrations:

```bash
php scripts/create-admin.php
```

Enter a full name, an unused email address, and a password of 12–72 characters twice. Password input is hidden in an interactive terminal. The command uses the database configured in `.env` and creates the account, profile and admin role in one transaction. It works when other users already exist and rejects an existing email without changing that account. Sign in at `/login` to go directly to `/admin`. Administrator accounts skip job-seeker setup, including accounts promoted with the grant command. Visiting the home or setup page also takes an administrator to their portal. Ordinary users still complete their normal setup.

The admin portal has its own layout and Jobs, Sources and Collection runs navigation. It excludes CVs, personal applications, inbox, billing and client settings. Administrator requests to client pages return to `/admin`; client mutations are rejected. The scheduler and worker also exclude administrator accounts from personal automation.

Set the following in the PHP host's private environment, never browser JavaScript:

```dotenv
COLLECTOR_ENABLED=true
COLLECTOR_PROJECT=your-project
COLLECTOR_REGION=africa-south1
COLLECTOR_JOB=synapse-job-collector
COLLECTOR_TOKEN=the-same-private-random-token-as-the-collector
COLLECTOR_MAX_DAILY_RUNS=4
```

Use at least 32 non-whitespace characters for the shared token; 32 random bytes encoded as 64 hexadecimal characters is recommended. The existing `GOOGLE_APPLICATION_CREDENTIALS` mechanism supplies the PHP host's Google service identity. Grant that identity `roles/run.jobsExecutorWithOverrides` on this job only, using `ADMIN_INVOKER_EMAIL` during collector deployment. Do not commit service-account files or hand their contents to the collector. Workload Identity Federation is also supported by Google's Application Default Credentials library.

Deploy from the collector repository after the callback API is reachable. Its script sets one task, parallelism one, no automatic task retries and a 20-minute hard timeout. The scheduler calls the job at `0 7 * * *` in `Africa/Nairobi`; the admin calls the same job with a reserved run ID. A job has no always-running HTTP service.

The collector defaults to Johannesburg (`REGION=africa-south1`). Cloud Scheduler uses a separate supported location (`SCHEDULER_REGION=us-central1`) and targets the Johannesburg job. The PHP host's `COLLECTOR_REGION` must match the collector region so manual executions use the same job as the daily schedule.

Shared discovery is the default. Search now applies role/location/salary/expiry preferences and personal daily limits to the stored catalogue immediately, without visiting job sites or waiting for a queue worker. Searches use keyset pagination, exclude already saved jobs and continue beyond the former 600-listing cutoff. A per-user lock prevents concurrent searches from exceeding personal allowances. Client setup and preferences no longer require configuring personal scrapers. Set `COLLECTOR_ENABLED=false` explicitly to retain legacy per-account collection. Gmail synchronisation and application workers keep their existing schedules. Central discovery does not process legacy email-alert source queues.

The application deploy workflow preserves the existing hosting environment. If the Synapse GitHub repository has `COLLECTOR_TOKEN` as a secret and `COLLECTOR_PROJECT`, `COLLECTOR_REGION`, `COLLECTOR_JOB` as variables, it configures only those collector fields and shared discovery through verified FTPS, then checks the authenticated status endpoint. With no repository token supplied, collector configuration stays under the separate deployment's control. Environment files are never published as build artifacts or printed in logs.

To connect an existing application to a deployed Cloud Run Job, set the same secret and variables in the GitHub `production` environment, then manually run **Configure Synapse collector**. This workflow updates the collector fields through the existing verified FTPS connection and checks the read-only status endpoint. It does not deploy application files or run database migrations. Keep the Cloud Scheduler entry paused until this verification succeeds.

Production deployments create a consistent SQL snapshot before applying pending migrations to an installed database. Snapshots are compressed, read back for integrity verification and stored privately at `_db-deploy/backups/` with web access denied and private filesystem permissions. One completed snapshot is reused for each release; an incomplete or changed snapshot blocks further DDL. Nothing is restored automatically. This works without hosting-managed backups or a configured backup time. The portable snapshot supports Synapse's InnoDB tables; schemas with views, triggers, routines or events require a native backup. A 128 MiB uncompressed allowance and time limit prevent an unbounded deployment request. `DB_DEPLOY_BACKUP_MODE=hosting-daily` retains the prior schedule policy for operators who explicitly use hosting backups.

Integration endpoints, relative to the final HTTPS Synapse application root:

- `POST /api/collector/claim`: claim an existing manual run or the idempotent daily run.
- `POST /api/collector/batch`: import a bounded batch with run identity and replay key.
- `POST /api/collector/finish`: record each source's actual outcome.
- `GET /api/collector/status`: check shared discovery, catalogue counts and source health without creating a run.

All four endpoints require `Authorization: Bearer <COLLECTOR_TOKEN>`. Write endpoints require JSON. The token must match the collector deployment. The read-only status response never contains credentials, profiles or private saved jobs.

## Admin controls

- **Jobs:** add, edit, publish and unpublish public vacancies. Edits are version-checked and preserved on later collection. Private CV/document IDs and factual approvals are excluded from catalogue records.
- **Sources:** configure built-in connectors and add structured JSON or RSS/Atom feeds. JSON jobs need explicit title/employer/description/source URL. RSS links need structured `JobPosting` detail pages. Unknown website layouts need a collector adapter; a URL alone is not a parser.
- **Collection runs:** request all enabled sources or a single source; inspect actual queued/running/completed/partial/failed states and imported counts. Refresh this view for progress. Missing Cloud Run configuration disables collection controls.

MyJobMag's advertised feed is initially enabled for up to 100 vacancies. Untouched BrighterMonday and Talent sources are enabled by migration 013 after successful bounded public checks; both currently cover technology roles. Fuzu, Indeed and Corporate Staffing remain paused pending their access requirements. Public-page collectors retain existing robots/TLS/SSRF checks. Fuzu requires the optional browser image and a cloud compatibility check. Indeed uses third-party SerpApi Google Jobs results, with at most two provider requests per collection. Its API key stays in the collector deployment. Account access alone is not permission to redistribute data.

## Execution and import safety

The PHP server reserves at most one active run, enforces a five-minute interval and defaults to four total runs per EAT day. These include failures and manual runs. The daily scheduled key is unique, so duplicate scheduler delivery cannot produce another daily import. A worker claims its execution identity and receives a run-specific credential and a source snapshot. Each batch contains at most twenty jobs and two MiB. Per-source record allowances are enforced by the server. Stable batch identities and payload hashes prevent duplicate counts or replaying different data with the same identity. Imports are transactional; a failed source does not undo successful batches.

An ambiguous Cloud Run launch stays reserved as `dispatch_uncertain`; it is not automatically retried. Queued/running reservations expire after thirty minutes, longer than the deployed task timeout. Imported data remains available after a worker crash. Budget caps are enforced through application limits, bounded source requests and task runtime; billing alerts alone are not the execution limiter.

Manual unpublishing survives refreshes. Saved user copies are independent; source refreshes do not rewrite their documents, notes, approvals or submitted applications. Deadlines hide expired catalogue jobs. This first version does not infer closure from a listing disappearing from a truncated source page, and vacancies without an advertised deadline can remain until an admin unpublishes them.

## Verification

```bash
php tests/admin.php
php tests/collector.php
node tests/collector-browser.mjs
composer test
```

Tests require the existing guarded `_test` database setup. They use isolated fixtures, fake callbacks and local servers; no paid API, email delivery or cloud deployment is part of ordinary validation.
