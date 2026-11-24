# Synapse

A Kenya-focused job search SaaS built with PHP 8.2+, MySQL/MariaDB, Tailwind CSS and inline Alpine.js. Apache serves the app directly. Node is used for asset preparation and optional browser collection; there is no Node web server.

Open **http://localhost/Synapse/** in this workspace. Everyone starts with one email-first entry screen: existing accounts enter their password; new visitors enter their name and choose a password. Continue with Google is also available when configured. New accounts then choose a career field and connected job sites, upload a CV and optional cover letter, see their first CV check, and optionally configure AI and automatic applications. Setup can be resumed or skipped. Each account has private documents, settings, applications and jobs in one shared database.

Entry and onboarding stay within the screen. Setup navigation remains visible; longer step content scrolls inside its card. The Google button uses the locally served official Google logo. Old `/register` links lead to the same entry screen as `/login`.

## Local setup

Apache must have `mod_rewrite` and `AllowOverride All` enabled for the project directory. The root `.htaccess` serves public assets and sends routes to PHP while denying source files, credentials and private storage. A dedicated virtual host may instead serve `Public/`.

Requirements: PHP 8.2+ with PDO MySQL, curl, sodium, mbstring, fileinfo, DOM/XML, ZIP and OpenSSL; MariaDB 10.6+ or MySQL 8.0+; Composer. Node is needed for CSS tooling, preparing PDF reader assets and the optional Fuzu browser collector.

```bash
composer install --no-interaction
cp .env.example .env
php scripts/key.php
```

Put the generated key in `APP_KEY`, configure the database credentials, and set `APP_URL` to the exact Apache URL including its subfolder. Use `SESSION_SECURE=false` for local HTTP and `true` for HTTPS. The PHP service account needs read access to `.env` and write access to `storage/private`, `storage/sessions`, and `storage/logs`.

```bash
php scripts/migrate.php
```

Now open the Apache URL. Registration creates the account; an administrator CLI account is not needed. Existing checked-in CSS, icons, fonts and PDF reader assets work immediately. Interactive behavior lives in the PHP views using inline Alpine bindings and nonce-authorised scripts. Shared Alpine components are in `Resources/Views/components/alpine.php`; the PDF reader lives in `Resources/Views/components/document-reader.php`. Job import and career profile logic live alongside their page markup. The pinned Alpine CSP runtime, Lucide library and PDF.js modules are served locally. Apache serves changes directly.

Prepare the local assets with:

```bash
npm ci
npm run build:pdf
npm run build:css
npm run watch:css
```

`build:pdf` copies the pinned `pdfjs-dist` 6.5.438 reader, worker, character maps, standard fonts and WebAssembly assets into `Public/Assets/pdfjs/6.5.438/`, with its licence in `Public/Assets/licenses/pdfjs.txt`. `build:css` produces minified CSS. `watch:css` updates minified CSS when templates or styles change. These commands do not start a web server. Existing `.env` values and `APP_KEY` must be preserved when upgrading.

## Plans and payments

| Feature | Free | Plus |
| --- | --- | --- |
| Browse, search, filter and save jobs | Yes | Yes |
| Manual application tracking and document uploads | Yes | Yes |
| Initial CV text/structure check | Yes | Yes |
| AI with a personal provider key | Yes; provider bills separately | Yes |
| Further CV checks, AI CV improvements and matching | | Yes |
| Built-in Gemini | | Yes, with a usage cap |
| Scheduled discovery, application preparation and email submission | | Yes |

Plus costs **KSh 200 for one calendar month** or **KSh 1,500 once for lifetime access to the service**. Monthly renewals are manual M-Pesa payments, not automatic debits. Entitlements are checked on server requests, AI calls and background tasks; an expired monthly subscription loses paid access.

Configure M-Pesa Daraja in the server environment:

- `MPESA_ENV=production` when live; `sandbox` for development.
- `MPESA_CONSUMER_KEY`, `MPESA_CONSUMER_SECRET`, `MPESA_SHORTCODE`, `MPESA_PASSKEY`.
- `MPESA_TRANSACTION_TYPE=CustomerPayBillOnline` for a Paybill, or the approved Buy Goods transaction type for the merchant setup.
- `MPESA_CALLBACK_URL=https://your-domain/Synapse/api/billing/mpesa/callback` (public HTTPS, exact installation path, no query string).

Checkout sends an STK prompt to a Kenyan M-Pesa number. Prices are selected by the server. An accepted prompt is not a completed payment. The callback is bound to the checkout with an unguessable token, and the server independently queries Daraja before granting access. Repeated confirmations grant access only once. The Plans page lets the user check a pending payment. Unknown initiation results remain unconfirmed for reconciliation; check M-Pesa before retrying. Sandbox payments cannot activate an account when `APP_ENV=production`.

The Plans page also accepts promo codes during onboarding and in the workspace. A lifetime code activates the same Plus entitlements immediately without an M-Pesa request or phone number. Codes are stored as SHA-256 hashes in `promo_codes`, with optional expiry/redemption limits and an enabled flag. Redemptions are recorded once per account; repeat requests are safe. Promo requests require authentication, CSRF and a rate limit. Migration `007_promo_codes.sql` installs the initial lifetime invitation code supplied by the owner.

Checkout stays disabled until configuration is present. No live M-Pesa payment was performed during implementation. Owner reconciliation for an initiation timeout or disputed payment is manual; there is no automatic refund integration.

## Google sign-in and Gmail

Set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET`, or set `GOOGLE_OAUTH_JSON` to the absolute server path of the downloaded **Web application** OAuth JSON. A single-line, single-quoted JSON value also works. Explicit client ID/secret variables take precedence. Downloaded `client_secret_*.json` files are ignored by Git. Register `https://your-domain/Synapse/Callback` with your Google web OAuth client and set both variables to that exact URL:

- Sign-in: `GOOGLE_SIGNIN_REDIRECT_URI=https://your-domain/Synapse/Callback`. This workspace uses `http://localhost/Synapse/Callback` (capital C).
- Gmail connection: `GOOGLE_REDIRECT_URI=https://your-domain/Synapse/Callback`.

The shared callback uses the matching server-side state to select sign-in or Gmail, and Gmail still requires the authenticated account that started the connection. Google registration requests basic identity only, using state and PKCE. Gmail sending/monitoring is connected separately with explicit permissions. Existing password accounts are not silently linked by email; use the existing password to sign in. Google sign-in remains visibly unavailable until configured. The legacy `/oauth/google/callback` endpoint also remains available for installations with a separately registered Gmail callback.

## Gemini and personal AI providers

For built-in AI, configure `GEMINI_API_KEY`, `GEMINI_MODEL`, `GEMINI_INPUT_PER_MILLION` and `GEMINI_OUTPUT_PER_MILLION`. Model prices are USD per million tokens. Use a currently available model from your Gemini account. The default hard budget for the shared provider is **USD 1 per user per month**, configurable only by the owner using `SITE_AI_MONTHLY_BUDGET_USD`. Users cannot increase this cap in Settings.

The current default is `gemini-3.1-flash-lite`, a stable model supporting structured outputs. On 20 November 2026 its standard text rates are **USD 0.25 input / USD 1.50 output per million tokens**. These are conservative budget-accounting rates even if a particular account has free-tier quota. Check [Google's model details](https://aistudio.google.com/docs/models/gemini-3.1-flash-lite) and [pricing](https://aistudio.google.com/docs/pricing#gemini-3.1-flash-lite) before changing the model or rates.

Alternatively, an existing configured provider can be copied into private site configuration:

```bash
php scripts/configure-site.php --ai-provider=PROVIDER_ID
```

The user can also add an encrypted personal key during setup or in Settings → AI. An API key and the provider’s model/prices are required. BYOK grants access to AI tools but does not bypass the Plus gate on CV improvements, ongoing checks, matching or automation. Keys are never rendered in HTML or returned to the browser. Paid accounts receive a private managed connection lazily on their first AI request.

The owner has now configured the server-side Gemini key and the connection has completed live matching and document generation. Credentials stay in the private environment and encrypted provider records. See [Google’s compatibility documentation](https://ai.google.dev/gemini-api/docs/openai).

## CV checks and applications

Documents use private Google Cloud Storage when `DOCUMENT_STORAGE=gcs`. The browser uploads directly with a 15-minute signed PUT URL, and viewing/download routes issue five-minute signed GET redirects after checking ownership. PHP receives upload metadata and does not proxy the browser's document bytes. Metadata, extracted text and application associations remain in MySQL. Extraction, generation and email jobs read cloud objects as needed; their temporary processing files are removed.

Users can favorite documents from the library or detail page and filter the library to Favorites. Three days after confirmed submission, unfavorited application attachments move to `GCS_COLD_STORAGE_CLASS` (`COLDLINE` by default) in the same private bucket. Main CVs, uploaded CV sources, templates, onboarding source documents, documents still processing, and attachments shared with drafts, recent submissions or unconfirmed sends stay available. The scheduler queues `archive_document` work before the automation pause/paid checks; a full worker processes retention even for paused/free accounts and rechecks eligibility under the account lock. Migration `011_document_retention.sql` adds this state.

Cold documents appear gray with downloads and previews unavailable. Favoriting a cold document verifies a rewrite to `STANDARD` storage before restoring access. Storage transitions use generation preconditions, size/checksum checks and retry markers to recover an interrupted database commit. Metadata, extracted text and version snapshots stay in MySQL for application history; this policy reduces active object storage costs rather than deleting application records. Google documents [retrieval fees and minimum storage durations](https://cloud.google.com/storage/docs/storage-classes): Coldline has a 90-day minimum, Nearline 30 days, and Archive 365 days. Restoring or deleting early can incur storage charges; choose the class to match expected reuse. No bucket-wide lifecycle rule is installed, so favorites remain protected.

PDF previews use a local [PDF.js](https://mozilla.github.io/pdf.js/) reader with canvas rendering and selectable text. The default fits the whole page to the available screen width and height, and multi-page scrolling stays inside the reader. Compact controls at the bottom provide page navigation, zoom, fit and expansion. Document details use a separate panel on small screens. The reader follows the authenticated viewing redirect to fetch the private cloud document directly in the browser. A failed preview offers retry and download actions.

Configure `GOOGLE_APPLICATION_CREDENTIALS` with the existing service-account JSON path, `GOOGLE_CLOUD_PROJECT`, and `GCS_BUCKET`. This workspace uses project `jobflow-515507` and the private `synapse_ke` bucket. Run `php scripts/migrate.php` and `php scripts/configure-cloud-storage.php --apply` to configure metadata tables and CORS for `APP_URL` plus localhost. Additional browser origins may be listed in `GCS_CORS_ORIGINS`. Keep uniform bucket access and public access prevention enabled. The service account needs object read/create/update/delete access; configuring CORS additionally needs bucket update access.

`php scripts/migrate-document-storage.php` previews existing local records; `--apply` copies and verifies checksums before switching their references. Run it under an account that can read the originals and write the private migration manifest in `storage/run`. Local copies remain until separately approved cleanup through `scripts/remove-migrated-document-copies.php --manifest=/absolute/path/to/manifest.json --apply`. A full worker handles `process_document` tasks even when automation is paused. Onboarding can continue while text extraction completes.

CVs and cover letter templates accept PDF or DOCX, are validated and stored privately, and are extracted for readable text. Scanned documents without text are explicitly left unscored. The free initial grade checks CV structure and terms associated with the selected career field. It is a deterministic text check, not a claimed ATS score or hiring prediction. Plus adds repeat checks and AI suggestions with a supported summary; suggestions are shown for review and never silently replace the original.

Question-based CV/cover-letter creation is disabled and labelled Coming soon, as requested. Users can skip uploads. Career facts still come only from the verified profile.

Document settings default to **Keep my uploaded design**. The separate [document renderer repository](../Synapse-document-renderer/README.md) edits selected paragraphs in the original DOCX and exports PDF with LibreOffice Writer, preserving the source package, tables, headers, assets and styles. Playwright prints HTML when no uploaded template exists. PDF-only CVs remain unchanged; editable DOCX is required to change their text while retaining the design. The app rejects edits that add pages rather than shrinking text. Word/LibreOffice spacing and unavailable fonts can still differ, so review the resulting pages before approving an application.

The renderer is maintained separately in `../Synapse-document-renderer` and is already deployed on Cloud Run for this workspace. Set its `DOCUMENT_RENDERER_URL` and `DOCUMENT_RENDERER_TOKEN` privately on each PHP host; the PHP deployment does not redeploy the renderer. Preserve-mode generation waits for this setup and never silently switches designs. A DOCX's **Create PDF preview** action shows the actual rendered layout. The extracted-text view is for reading/search only. The existing simple app layout remains an explicit document setting.

Automatic applications currently support **reviewed email applications through connected Gmail**. The pipeline collects jobs, analyses matches, prepares documents, and queues eligible approved emails. Profile facts, job instructions and application attachments must be verified before sending. Defaults are Manual mode and a five-per-day send limit. Ambiguous sends are not retried automatically, avoiding duplicate applications. Employer website forms must be completed on the original website. Browser form submission adapters are not implemented.

Selecting Automatic now enables scheduled discovery, matching and preparation together, while retaining the chosen match threshold and daily send limit. Existing open jobs are queued for matching once the verified career profile and AI connection are available; jobs already queued or matched are not added twice. Dashboard and Automation settings show missing setup and whether any open vacancies accept email. A distinct application-worker heartbeat prevents a discovery-only worker from being shown as ready to process applications. Uploaded CV text must be imported into the career profile and checked; uploading alone does not create verified career facts.

## Connected job sites

Onboarding only offers sites in the private site catalogue that have been configured and passed a connection check. The reset retained the three connected sites: MyJobMag, BrighterMonday and Talent.com. The existing BrighterMonday/Talent collectors cover technology roles, so other fields currently use MyJobMag. Fuzu, Corporate Staffing, Google Jobs and Indeed adapters remain available in advanced discovery settings but are not advertised as connected onboarding options.

To update the catalogue after checking/enabling site collectors:

```bash
php scripts/configure-site.php
```

RSS/public collectors need no AI key. Google Jobs and Indeed use the existing encrypted SerpApi connection. Fuzu requires Playwright Chromium, its system libraries, and an available display where needed. Source failures are reported without invented vacancies. See [integration details](docs/integrations.md).

## Background work

Automatic mode emails strong eligible matches that require a manual application to the **account's registration email**. The match must meet the account's Automatic threshold (80% by default); closed, archived, uncertain, ineligible and already submitted vacancies are excluded. The message contains the employer link, match score, deadline and an authenticated workspace link to any prepared CV/cover letter. Documents remain private. An alert never marks a job submitted or uses a daily application slot. The scheduler continues queuing approved email applications in descending match order, subject to the existing review and send rules.

These notifications use [PHPMailer](https://github.com/PHPMailer/PHPMailer) with authenticated SMTP. Employer application emails continue using the user's connected Gmail account. Configure the site sender in `.env`:

```dotenv
SMTP_HOST=smtp.gmail.com
SMTP_PORT=587
SMTP_ENCRYPTION=tls
SMTP_USERNAME=your-sender@gmail.com
SMTP_PASSWORD=your-app-password-without-spaces
SMTP_FROM_EMAIL=
SMTP_FROM_NAME=Synapse
```

An empty `SMTP_FROM_EMAIL` uses `SMTP_USERNAME`; use a verified sender/alias if overriding it. Gmail's [official PHPMailer example](https://github.com/PHPMailer/PHPMailer/blob/master/examples/gmail.phps) documents app passwords and the alternative `SMTP_PORT=465` with `SMTP_ENCRYPTION=ssl`. Both modes require TLS with certificate verification; SMTP debug output is disabled. Keep credentials private. Each recipient gets only their own saved opportunity.

Migration `008_manual_job_alerts.sql` adds a durable per-account/per-job outbox. Missing SMTP credentials leave alerts pending without blocking matching, preparation or Gmail applications; the next scheduler run queues them once credentials are supplied. Successful alerts are not repeated after re-analysis or scheduler retries. An unconfirmed send or worker crash is held for mailbox reconciliation rather than automatically replayed. The application page shows pending/sent/unconfirmed alert status; notification delivery is separate from application status. After checking the sender mailbox, an owner can reconcile an unconfirmed outbox record before authorizing a new attempt. No live SMTP delivery was tested without the owner's app password.

Apache serves web requests immediately. Unattended searches and sends still require a PHP worker and scheduler. Prefer the same service account as Apache; installations with separate accounts need a private shared group or named ACL for documents:

```bash
php scripts/worker.php
php scripts/worker.php --once
php scripts/worker.php --drain
```

For shared hosting, run `php scripts/cron.php` every minute: it combines the scheduler and a bounded full worker under one lock. On a VPS, use `php scripts/schedule.php` with a supervised worker instead. Templates live in `deploy/`. This workspace's cron now runs the full worker. The optional `--discovery-only` mode excludes preparation and sending. The scheduler and worker both enforce paid automation access. Manual search stays free. No browser session or npm command needs to remain open for Apache to serve pages.

Private files default to mode `0600`. Use `PRIVATE_FILE_MODE=0640` only with a private shared group/ACL that grants the PHP and worker accounts read access. This workspace has named directory/default ACLs for its worker and Apache users and uses `0640`; document files remain inaccessible to other users. `0600` and `0640` are the only accepted modes. File reads remain behind authenticated ownership checks.

## Reset and backup

The requested reset was performed on the configured `jobflow` database. All previous users, jobs, application data and user settings were removed; source catalogue and server configuration remain. The pre-reset snapshot is private under ignored `storage/run/backups/`. Treat it as sensitive and keep it off public hosting.

For another explicitly intended reset:

```bash
php scripts/reset-workspace.php --confirm=jobflow
```

The command requires the exact configured database name, acquires worker locks, writes a private JSON backup, preserves working site connections, removes user data transactionally, archives private originals and invalidates old sessions. Migration history remains. It does not drop the database or create a second production database.

## Verification

```bash
composer lint
composer test
node tests/browser.mjs
node tests/saas-browser.mjs
node tests/alpine-browser.mjs
node tests/google-signin-browser.mjs
node tests/document-preview-browser.mjs
node tests/document-retention-browser.mjs
node tests/entry-browser.mjs
node --test tests/fuzu-browser.mjs
```

Tests require a separate database ending in `_test`, configured with `JOBFLOW_TEST_DATABASE`; they refuse to use the production database. `DB_SOCKET`, `DB_USERNAME` and `DB_PASSWORD` can point tests to an isolated local instance. Browser tests start and stop their own temporary PHP server, use test users only, and write screenshots under ignored `tests/.runtime/`. Set `CHROMIUM_PATH` to an installed Playwright-compatible Chromium binary.

The SaaS suite covers account isolation, catalogue validation, CV grades, BYOK versus managed access, plan expiry, M-Pesa pricing, callback forgery, query identity checks and idempotent activation. Live Gemini, Google and M-Pesa still require actual deployment credentials; fixture tests do not claim live external delivery.

The ordinary suites force local test storage and never write to the live bucket. `JOBFLOW_CLOUD_TEST=1 node tests/cloud-storage-browser.mjs` is an explicit live-cloud integration test: configure its temporary `http://127.0.0.1:8857` CORS origin first, run it separately from other browser suites, then restore normal CORS. It uses a separate `_test` database, an `integration/<random>` object prefix, and removes its test objects afterward.

## Production deployment

GitHub Actions is configured for `https://jobflow.mystuffs.co.ke`, with tests before deployment, authenticated SQL migrations before the application upload, and final public checks. The FTP-visible destination, hosting environment and GitHub credentials must be configured before the first release. See [hosting setup and required Actions settings](docs/deployment.md) and [database migration and recovery commands](database/README.md).
