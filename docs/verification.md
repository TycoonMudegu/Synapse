# Verification

Tests use synthetic fixtures only in a database ending in `_test`. No job, profile, document or submission fixture is seeded into the application database. External side effects are not performed by automated tests.

Current result: **110 PHP checks passed** (63 application checks, 21 installation-URL checks and 26 source integration checks), together with the asset build and PHP syntax checks. The browser workflow now includes **22 checks** with source setup and persistent technology-role filtering. The earlier 20-check workflow passed in both modes: the PHP development server at a root URL and an isolated Apache/mod_php installation under `/autoaplicator/`.

## Automated checks

- Apache serves the project root through its front controller, loads prefixed assets/fonts, denies private/source paths and returns standard HTTP 403 for rejected CSRF tokens.
- Root, subfolder and nested installation URLs, request-route normalisation, external OAuth URLs and API prefixes.
- PHP syntax across controllers, services, repositories, bootstrap, routes, views and scripts.
- Full Tailwind CLI / esbuild build and locally bundled font copying.
- Secretbox round trip, random nonces and tamper detection.
- CSRF validation, unsafe URL rejection, public-address checks including shared and benchmarking ranges, canonical URL deduplication, robots rule handling and unsafe HTML removal.
- JSON schema validation, mandatory fields, enums, numeric bounds and rejection of invented career-entry indices.
- Strict calendar dates, email MIME generation and header-injection rejection.
- Real MariaDB migrations, relational profile persistence, user ownership, prepared search inputs, unique jobs/applications, source activation gates and settings persistence.
- Real PDF generation/text parsing and DOCX ZIP/XML generation/parsing.
- Master CV selection, application attachment retention, explicit review/approval, Manual Mode send prevention, external confirmation and permitted state transitions.
- Profile edits invalidate previous match results; profile revision comparison prevents same-second edits from being mistaken for an unchanged profile.
- Queue reservation/deduplication/ownership, a real deadline task, bounded retries and non-replayed submission failures.
- AI model selection, prompt isolation, token usage and budget reservation using a deterministic test provider; malformed compatible-provider responses are rejected. A test provider never represents a live production connection.
- Chromium workflow: protected-page redirect, invalid credentials/CSRF, password visibility, session rotation, real zero dashboard metrics, all application/settings pages, profile save/persistence, manual job creation/editing, application uniqueness, cover-letter export/attachments/review/approval, dangerous upload rejection, valid DOCX upload/download, explicit Automatic Mode consent, protected paths, mobile navigation, 390px overflow checks, logout and uncaught-JavaScript checks.

Run commands and database setup are in README.md. Browser tests use the local browser pointed to by `CHROMIUM_PATH` if supplied. On this host Chromium needed shared libraries loaded from a temporary directory; no system packages were changed.

## Not live-verified

No provider keys or Google account were supplied. Live AI/vision quality, vendor-specific parameter support, OAuth consent/token refresh and Gmail sending/reading require configuration and live verification. MyJobMag’s advertised RSS feed was verified through the application safeguards. Applying Technology & IT focus imported five additional matching vacancies; Discover now shows six matching saved roles, with previous unrelated jobs retained in My jobs. Local discovery cron was installed with existing schedules preserved. No application has been submitted by this implementation session.

This code ships generic feed/JSON adapters, a live-tested official MyJobMag detailed RSS adapter, BrighterMonday/Fuzu email-alert ingestion and Gmail email submission. Recruitment API vendors and browser form-fill extensions are not supplied. Scanned-PDF OCR without a vision-image workflow and cross-thread reply classification are not claimed as complete integrations.


## Requested source integrations

`tests/sources.php` covers explicit MyJobMag field parsing, unknown facts, XML limits, source URL restrictions, Kenyan location matching, domain impersonation rejection, HTML link preservation, per-link deduplication, preset idempotency, Gmail scope/connection requirements, mocked read-only email ingestion, user ownership, per-vacancy pending records and retryable missing-AI failures. These Gmail fixtures run only in `_test` and do not prove a live Gmail connection.

Technology-focus checks cover the requested job families, rejection of nontechnical roles and incidental computer literacy, whole-word and hyphenated-phrase matching, optional OR keywords, exclusions, location and expiry checks, settings persistence and account ownership. A structured-feed fixture places 40 unrelated entries before technical jobs and verifies that two matching jobs are imported under a two-job processing cap. A malformed entry cannot hide later valid feed items. Discover filters before limiting results and retains unrelated saved jobs in My jobs.

The browser workflow also verifies that all three source cards render, adding Fuzu persists and Needs connection is displayed until Gmail is configured. No live subscription or BrighterMonday/Fuzu alert delivery is claimed.
