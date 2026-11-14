# Synapse

**JobFlow — AI job search and application management.**

A private, Kenya-focused job search and application workspace built with PHP 8.2+, PDO, MySQL/MariaDB, Tailwind CLI and vanilla JavaScript. The interface uses locally hosted Nunito, Lucide icons and the supplied ivory, powder blue, deep blue and earth brown palette.

There is no demo dataset, public registration, preset administrator password or simulated external integration. Dashboard numbers come from the database. Each external connection starts unconfigured or untested.

## Requirements

- PHP 8.2+ with `pdo_mysql`, `curl`, `sodium`, `mbstring`, `fileinfo`, `dom`, `xml`, `simplexml`, `zip` and `openssl`.
- MySQL 8.0+ or MariaDB 10.6+ (the queue uses `FOR UPDATE SKIP LOCKED`).
- Composer 2 and Node.js 20+ / npm for development and asset builds.
- HTTPS and a PHP-capable web host for production. Cloudflare Workers/static hosting cannot run this application.
- A process supervisor for the worker and cron for scheduling.

## Install

```bash
composer install --no-interaction
npm ci
npm run build
cp .env.example .env
php scripts/key.php
```

Put the generated key into `APP_KEY` in `.env`. Keep the same key when upgrading or restoring a backup: encrypted API keys and OAuth tokens depend on it. Never commit `.env`.

Create a database and a dedicated account with an administrator connection to MySQL. Replace the password below with a strong private password:

```sql
CREATE DATABASE jobflow CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'jobflow'@'localhost' IDENTIFIED BY 'replace-with-a-private-password';
GRANT ALL PRIVILEGES ON jobflow.* TO 'jobflow'@'localhost';
```

Configure `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` and optionally `DB_SOCKET` in `.env`. Set `APP_URL` to the exact origin you will use. Use `SESSION_SECURE=true` with HTTPS; use `false` only for local HTTP development.

```bash
php scripts/migrate.php
php scripts/create-admin.php
npm run dev
```

The administrator command prompts for your name, email and password. Password entry is hidden in an interactive terminal. It refuses to create another account after the initial administrator exists. Open **http://127.0.0.1:8080** and sign in. Set your career profile up before using AI analysis.

Ensure that `storage/private`, `storage/sessions` and `storage/logs` are writable by the PHP/worker service account. For a dedicated production virtual host, serve **`Public/`** as the web root. Apache subfolder installations can use the root entry point and `.htaccess` as described below. Original uploads and generated documents are stored privately, never in `Public/`.

### Open directly through Apache

If Apache already serves the parent folder, open **http://localhost/autoaplicator/**. The root `index.php` forwards to the existing application, and `.htaccess` routes requests and serves only compiled assets. Source code, configuration, dependencies and private storage are denied direct HTTP access. Links, API redirects, forms, cookies and fonts respect the detected installation path, including nested subfolders.

Apache 2.4 needs `mod_rewrite` and permission to use the checked-in `.htaccess` files (`AllowOverride All` for this local project directory). Set `APP_URL=http://localhost/autoaplicator` and `SESSION_SECURE=false` for this local HTTP URL. Use the same path in the registered Gmail callback if connecting Gmail here.

The Apache service account must be able to read `.env` and write the private storage directories. Keep credentials private; grant access to the service account rather than making them world-readable. See [docs/deployment.md](docs/deployment.md#apache-subfolder-development) for permissions and worker setup.

### This workspace

Dependencies and compiled assets are already installed here. A gitignored `.env` has been generated with a unique encryption key, local-only cookie settings and a dedicated account on an isolated MariaDB instance with its private socket at `storage/run/mysql.sock`. The application database is `jobflow`; automated fixtures use `jobflow_test`. **No administrator has been created in the application database.** Run `php scripts/create-admin.php` to choose your credentials.

The `/tmp` database is a development/test instance, not durable production hosting. If it is no longer running, configure your own MySQL/MariaDB service using the installation instructions above. Nothing has been deployed to an external host.

## What works

| Area | Implemented behaviour |
| --- | --- |
| Authentication | Hashed passwords, private initial CLI installation, CSRF, server-side sessions, session rotation, rate-limited sign-in, rotating 30-day remember tokens, password changes and session revocation. |
| Workspace | Responsive desktop/mobile navigation, Nairobi-time greetings, real statistics, matches, deadlines, recorded activity and automation status. |
| Career profile | Editable contact details, summary, employment, education, skills, certifications, languages, achievements, references, links and preferences; explicit factual verification. |
| Jobs | Search/filter/sort, save, edit, shortlist/archive, deadline expiry, URL and fingerprint deduplication and user ownership checks. |
| Manual imports | Direct details entry, AI structuring of pasted advertisements, permitted public HTTPS URLs, image posters through a vision-capable model, and text-based PDF advertisements. Extracted fields remain editable before saving. |
| Documents | MIME/size/ZIP validation, private originals, PDF/DOCX CV extraction, CV-to-profile drafts, master CV selection, PDF/DOCX generation, authenticated preview/download, rename/delete, application associations and version links. |
| AI | Provider interface and OpenAI-compatible transport, configurable endpoint/model/token parameter, per-task provider assignments, fallback, encrypted keys, connection tests, local JSON schema validation, estimated usage/budget accounting and configurable matching weights. |
| Matching | Scores, explanations, strengths, gaps and mandatory-condition checks, with eligibility separate from percentage. Changing profile/job facts invalidates stale analyses and approvals. |
| Application preparation | Reorders verified career entries, drafts a supported summary and British English cover letter, exports attachments, saves versions and displays original/tailored summary changes. Documents require review. |
| Application tracking | Draft → review → approval → submission → interview/offer/rejection/withdrawal, external confirmations, interview scheduling and duplicate-send protection. |
| Gmail | Real Google OAuth flow, encrypted token storage/refresh, sending-account selection through reconnect, signatures, self-test email, approved applications with attachments, read-only relevant-thread and job-alert synchronisation, inbox and review flags. |
| Discovery | MyJobMag detailed RSS; direct public-page collectors for BrighterMonday/Fuzu; optional email alerts; generic RSS/Atom and JSON sources; real connection checks, deduplication, limits and collection logs. |
| Background work | Database-backed queue, per-task/user locks, bounded retries, stale-task recovery, worker heartbeat, cron scheduler, pause controls and browser progress polling. |

All external functions require their corresponding configuration; errors are surfaced rather than replaced with fake results.

## Your first end-to-end application

1. Save and verify **My CV → Career profile**, or upload a PDF/DOCX CV and import its extracted draft with a configured AI provider.
2. Use **Add job → Enter details**. Provide the real title, employer, description and instructions. Keep unknown salary and deadline blank. Verify the original advertisement.
3. Open the job and choose **Prepare application**. This creates one draft per user/job.
4. Upload your CV and use the cover letter editor, or configure AI and choose **Prepare with AI**. For AI preparation, the worker must be running.
5. Open both attachments, correct any factual issues, write the email and save the verified package.
6. Change its status to **Approved**. Manual Mode keeps the application on your side. For an external employer form, open the original advertisement, complete the protected steps yourself, then record the external confirmation.
7. To send an approved email application, connect Gmail and enable Approval Mode with email submissions permitted. The scheduler or **Send approved application** queues the send. Submission is recorded only after Gmail returns a message ID.

## Workers and schedules

Run the worker independently of browser sessions:

```bash
php scripts/worker.php
```

Process one available task and exit:

```bash
php scripts/worker.php --once
```

Call the scheduler every minute using cron. Replace the absolute checkout path and PHP binary if needed:

```cron
* * * * * cd /var/www/jobflow && /usr/bin/php scripts/schedule.php >> storage/logs/scheduler.log 2>&1
```

The scheduler dispatches configured discovery, deadline checks, Gmail sync and approved submissions. New-job analysis and suitable-application preparation are queued when enabled. **Both the scheduler and worker are needed for unattended processing.** Settings never pretend to start an operating-system service. Examples are in `deploy/`.

A fresh installation defaults to **Manual Mode**, discovery scheduling disabled, automatic analysis/preparation disabled and no Gmail connection. For this configured workspace, six-hour discovery scheduling is enabled and the three built-in sources use direct collection. MyJobMag and BrighterMonday have passed live checks; Fuzu currently returns HTTP 403 from this server and stays inactive with that error displayed. Automatic Mode requires explicit confirmation and still requires reviewed documents, verified job instructions, an eligible match, score threshold, daily capacity and a supported permitted channel.

Settings → Job discovery → Role focus can restrict discovery to **Technology & IT**: software/web development, PHP/MySQL, frontend/backend, systems administration, databases, networking, cloud/DevOps, cybersecurity and technical support. This workspace uses that focus. Optional comma-separated keywords narrow the focus using any matching whole word or phrase; `IT` does not match inside `audit`, and `front-end` matches `front end`. Nontechnical roles are excluded even at a technology employer; generic titles need explicit technical duties. Discover applies current preferences to saved results; My jobs retains all saved opportunities.

To apply this focus to an existing account from the CLI while retaining its other preferences and queuing discovery:

```bash
php scripts/set-job-focus.php <user-id> technology
```

MyJobMag scans up to 200 structured feed entries before applying the per-run processing allowance, so unrelated entries at the start do not consume it. Incomplete entries are skipped with a count in `storage/logs/php.log`; no missing employer or vacancy facts are invented. Text/email extraction still obeys the processing and AI spending limits.

A timed-out or interrupted send is never automatically replayed: it moves to **Awaiting external action**. Check Gmail Sent using the recorded Message-ID and record the real confirmation or withdraw it. This deliberately favours avoiding duplicate applications over unattended retries.

### Local discovery cron

The local checkout has a user cron block that calls the scheduler every minute and drains only discovery, Gmail sync, deadlines and job analysis. The saved discovery interval determines when the feed is collected (currently every six hours). Existing unrelated cron entries are preserved. The template is `deploy/discovery-cron.example`.

```bash
php scripts/worker.php --drain --discovery-only
```

`--drain` handles available tasks and exits, bounded to 20 tasks or approximately 50 seconds between tasks. `--discovery-only` leaves document preparation and submission tasks for the full worker under the web service account. A cron lock prevents overlapping fallback workers.

## External configuration

See [docs/integrations.md](docs/integrations.md) for setup and source formats.

- **AI**: supply your own OpenAI-compatible HTTPS base URL, actual model identifier, key, current pricing, token parameter and output mode. Select providers for screening, tailoring and letters; then test. Gemini can use its documented OpenAI-compatibility endpoint. There are no hard-coded model identifiers.
- **Gmail**: create a Google OAuth web client, configure the environment variables and exact callback, then connect in Settings. Read-only permission is added only when monitoring is enabled. Google may require OAuth verification depending on deployment/audience/scopes.
- **Sources**: add RSS/Atom or JSON APIs where you have confirmed permitted automated access. A successful connection check is required before activation. No recruitment platform credentials are stored.

AI budgets are conservative reservations using **your entered token prices**, not provider billing reconciliation. Unknown or failed request usage retains the reservation. Review your provider's actual invoice separately.

## Verification

```bash
composer lint
composer test
php tests/run.php --unit
php tests/urls.php
npm run build
npm run test:browser
```

Database tests use `jobflow_test`, never `jobflow`. Create that database and grant the same database user access, or set `JOBFLOW_TEST_DATABASE` to another name ending in `_test`. The test runner refuses a database without that suffix. Tests create temporary users/files and remove them afterwards.

For browser testing, install Playwright Chromium on your machine:

```bash
npx playwright install --with-deps chromium
npm run test:browser
```

`CHROMIUM_PATH` can point at an existing Chromium/headless-shell binary. The browser test starts and stops its own local PHP server on port 8848, uses only the test database and leaves screenshots in ignored `tests/.runtime/`.

Verified during implementation: PHP syntax, asset compilation, database migrations, real PDF/DOCX round trips, ownership/CSRF/SSRF protections, state transitions, budgets, queue behaviour, all page/settings routes and a browser workflow covering login → career profile → job creation/edit → application → real attachments → factual review → approval. Desktop and 390px mobile layouts are covered. See [docs/verification.md](docs/verification.md).

## Boundaries and remaining integration work

- Live AI responses, model-specific vision support, live permitted source collection, Google OAuth and actual email delivery require your credentials/permissions and were **not** exercised against a live account.
- Scanned PDFs with no extractable text need an image upload to a vision model or manual transcription. There is no bundled local OCR service.
- MyJobMag uses its official detailed RSS feed. BrighterMonday and Fuzu have public-page collectors that read explicit structured vacancy data without Gmail or AI. Collection depends on site access and markup; blocked responses are reported and disable the source. Optional email-alert mode still requires Gmail, website subscriptions and an AI screening provider. No LinkedIn or government-site scraping adapter is enabled.
- Provider-specific recruitment submission APIs and browser autofill extensions are extension points, not implemented vendor integrations. External applications use assisted drafting, attachment downloads and the original employer page; protected forms are completed by you.
- AI-written summaries and letters require factual review. Tailoring preserves original employers, qualifications, dates, achievements and skills; it reorders them and drafts a summary rather than freely rewriting factual career history.
- Response association uses known Gmail thread IDs. Possible interviews are flagged, not blindly converted into status changes or calendar appointments. Cross-thread guesses require review.
- Original uploaded CV layouts are preserved as downloads. Generated CVs use a clean text-based template; there is no drag-and-drop template designer or pixel-perfect reconstruction of an uploaded CV.
- There is no public registration, multi-user administration UI, password-reset email flow, MFA, calendar integration or cloud deployment included. Storage/repositories are scoped for future multiple users.

## Architecture

See [docs/architecture.md](docs/architecture.md), [docs/security.md](docs/security.md) and [docs/deployment.md](docs/deployment.md). Ordered migrations live in `database/migrations/`. Controllers handle requests, services implement workflows, repositories own SQL and source/submission/provider interfaces isolate external integrations.
