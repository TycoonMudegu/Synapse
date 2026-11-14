# Verification

Tests use synthetic fixtures only in a database ending in `_test`. No job, profile, document or submission fixture is seeded into the application database. External side effects are not performed by automated tests.

Current result: **84 PHP checks passed** (63 application checks and 21 installation-URL checks), together with the asset build and PHP syntax checks. The **20 Chromium checks pass in both modes**: the PHP development server at a root URL and an isolated Apache/mod_php installation under `/autoaplicator/`.

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

No provider keys, Google account or authorised real job feed were supplied. Live AI/vision quality, vendor-specific parameter support, OAuth consent/token refresh, Gmail sending/reading, source terms/access and production cron/process supervision therefore require configuration and live verification. No application has been submitted by this implementation session.

This code ships generic feed/JSON source adapters and Gmail email submission. Recruitment API vendors and browser form-fill extensions are not supplied. Scanned-PDF OCR without a vision-image workflow and cross-thread reply classification are not claimed as complete integrations.
