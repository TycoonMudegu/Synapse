# Verification

Tests use synthetic fixtures only in a database ending in `_test`. No job, profile, document or submission fixture is seeded into the application database. External side effects are not performed by automated tests.

Current result: **118 PHP checks passed** (63 application checks, 21 installation-URL checks and 34 source integration checks), together with the asset build and PHP syntax checks. The browser workflow includes **22 checks**, including public/email source-mode switching without external requests and persistent technology-role filtering. The earlier 20-check workflow passed in both modes: the PHP development server at a root URL and an isolated Apache/mod_php installation under `/autoaplicator/`.

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

No provider keys or Google account were supplied. Live AI/vision quality, vendor-specific parameter support, OAuth consent/token refresh and Gmail sending/reading require configuration and live verification. MyJobMag’s advertised RSS feed supplies six matching saved IT roles. A live BrighterMonday public collection imported four additional matching vacancies without Gmail or AI; Discover now shows ten matching opportunities, with previous unrelated jobs retained in My jobs. Fuzu returned HTTP 403 through both the ordinary request client and Chromium, including after allowing browser JavaScript to run. Local discovery cron remains installed. No application has been submitted by this implementation session.

This code ships generic feed/JSON adapters, live-tested MyJobMag RSS and BrighterMonday public-page collectors, a Fuzu public-page collector currently blocked with HTTP 403, optional email-alert ingestion and Gmail email submission. Recruitment API vendors and browser form-fill extensions are not supplied. Scanned-PDF OCR without a vision-image workflow and cross-thread reply classification are not claimed as complete integrations.


## Requested source integrations

`tests/sources.php` covers explicit MyJobMag field parsing, unknown facts, XML limits, source URL restrictions, Kenyan location matching, domain impersonation rejection, HTML link preservation, per-link deduplication, preset idempotency, Gmail scope/connection requirements, mocked read-only email ingestion, user ownership, per-vacancy pending records and retryable missing-AI failures. These Gmail fixtures run only in `_test` and do not prove a live Gmail connection.

Technology-focus checks cover the requested job families, rejection of nontechnical roles and incidental computer literacy, whole-word and hyphenated-phrase matching, optional OR keywords, exclusions, location and expiry checks, settings persistence and account ownership. A structured-feed fixture places 40 unrelated entries before technical jobs and verifies that two matching jobs are imported under a two-job processing cap. A malformed entry cannot hide later valid feed items. Discover filters before limiting results and retains unrelated saved jobs in My jobs.

Public-source checks cover JSON-LD employer/address graph resolution, whole-field extraction, script removal, missing facts and ambiguous postings, canonical same-host detail links, request caps, per-host robots caching, query restrictions, immediate blocked-response failure, public-mode defaults, account ownership, mode persistence, filtering and duplicate prevention without Gmail or AI. Already saved structured vacancies do not consume the processing allowance: a regression check imports a later new vacancy under a one-job cap. Fuzu parser fixtures do not prove live collection: the actual site returns HTTP 403 from this server.

The browser workflow verifies that all three source cards render, adding Fuzu defaults to public mode without asking for Gmail, switching to email mode displays Needs connection, and switching back retains a public source. No live subscription or BrighterMonday/Fuzu alert delivery is claimed.
