# Shared job collection and administration

The independently deployable collector lives in the sibling `../Synapse-job-collector` Git repository. Synapse owns its source configuration, run ledger and shared catalogue. The collector has no database credentials, user profiles, application documents or authority to submit applications. It authenticates to three bounded import endpoints and exits when collection finishes.

## Application setup

Apply additive migration `012_job_collector.sql` through the existing migration workflow before deploying the application code. It adds an administrator flag, collector sources/runs/batches, the public catalogue and a provenance link from a user's saved job. Existing users default to non-admin. Grant the owner's existing account explicitly from a trusted CLI:

```bash
php scripts/admin.php grant --email=owner@example.com
```

`revoke` removes that permission. Registration, account settings and request bodies cannot grant administrator access. `/admin` and every admin API action enforce the role on the server. Ordinary authenticated users can browse `/catalogue` and save a published, unexpired vacancy to their own workspace. Copies remain unverified, and existing application approval rules apply.

Set the following in the PHP host's private environment, never browser JavaScript:

```dotenv
COLLECTOR_ENABLED=false
COLLECTOR_PROJECT=your-project
COLLECTOR_REGION=us-central1
COLLECTOR_JOB=synapse-job-collector
COLLECTOR_TOKEN=the-same-private-random-token-as-the-collector
COLLECTOR_MAX_DAILY_RUNS=4
```

Use at least 32 non-whitespace characters for the shared token; 32 random bytes encoded as 64 hexadecimal characters is recommended. The existing `GOOGLE_APPLICATION_CREDENTIALS` mechanism supplies the PHP host's Google service identity. Grant that identity `roles/run.jobsExecutorWithOverrides` on this job only, using `ADMIN_INVOKER_EMAIL` during collector deployment. Do not commit service-account files or hand their contents to the collector. Workload Identity Federation is also supported by Google's Application Default Credentials library.

Deploy from the collector repository after the callback API is reachable. Its script sets one task, parallelism one, no automatic task retries and a 20-minute hard timeout. The scheduler calls the job at `0 7 * * *` in `Africa/Nairobi`; the admin calls the same job with a reserved run ID. A job has no always-running HTTP service.

After verifying the first successful import, set `COLLECTOR_ENABLED=true` and restart persistent PHP workers. User discovery then searches the stored catalogue, applying role/location/salary/expiry preferences and personal daily limits. With this flag false, existing per-account collection remains available. Gmail synchronisation and application workers keep their existing schedules. Central discovery does not process legacy email-alert source queues; use the original collection mode if those private imports are required.

## Admin controls

- **Jobs:** add, edit, publish and unpublish public vacancies. Edits are version-checked and preserved on later collection. Private CV/document IDs and factual approvals are excluded from catalogue records.
- **Sources:** configure built-in connectors and add structured JSON or RSS/Atom feeds. JSON jobs need explicit title/employer/description/source URL. RSS links need structured `JobPosting` detail pages. Unknown website layouts need a collector adapter; a URL alone is not a parser.
- **Collection runs:** request all enabled sources or a single source; inspect actual queued/running/completed/partial/failed states and imported counts. Refresh this view for progress. Missing Cloud Run configuration disables collection controls.

MyJobMag's advertised feed is initially enabled. BrighterMonday, Fuzu, Talent, Indeed and Corporate Staffing are seeded paused until configured and enabled. Public-page collectors retain existing robots/TLS/SSRF checks. Fuzu requires the optional browser image and a cloud compatibility check. Indeed uses third-party SerpApi Google Jobs results, with at most two provider requests per collection. Its API key exists only in the collector's Secret Manager configuration. Talent/Indeed collection and reuse permissions need to be established for the intended use; account access alone is not permission to redistribute data.

## Execution and import safety

The PHP server reserves at most one active run, enforces a five-minute interval and defaults to four total runs per EAT day. These include failures and manual runs. The daily scheduled key is unique, so duplicate scheduler delivery cannot produce another daily import. A worker claims its execution identity and receives a run-specific credential and a source snapshot. Each batch contains at most twenty jobs and two MiB. Per-source record allowances are enforced by the server. Stable batch identities and payload hashes prevent duplicate counts or replaying different data with the same identity. Imports are transactional; a failed source does not undo successful batches.

An ambiguous Cloud Run launch stays reserved as `dispatch_uncertain`; it is not automatically retried. Queued/running reservations expire after thirty minutes, longer than the deployed task timeout. Imported data remains available after a worker crash. Budget caps are enforced through application limits, bounded source requests and task runtime; billing alerts alone are not the execution limiter.

Manual unpublishing survives refreshes. Saved user copies are independent; source refreshes do not rewrite their documents, notes, approvals or submitted applications. Deadlines hide expired catalogue jobs. This first version does not infer closure from a listing disappearing from a truncated source page, and vacancies without an advertised deadline can remain until an admin unpublishes them.

## Verification

```bash
php tests/collector.php
node tests/collector-browser.mjs
composer test
```

Tests require the existing guarded `_test` database setup. They use isolated fixtures, fake callbacks and local servers; no paid API, email delivery or cloud deployment is part of ordinary validation.
