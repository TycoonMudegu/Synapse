# Architecture

`Public/index.php` is the front controller. `Bootstrap/App.php` loads Composer, environment configuration, timezone, sessions and response security headers. `Routes/web.php` declares routes. The lightweight router applies authentication, CSRF and request limits before controllers run.

Controllers validate HTTP intent and invoke services/repositories. Services coordinate profile verification, imports, AI tasks, document generation, discovery, OAuth and application submission. Repositories contain prepared PDO statements and user-scoped queries. Views escape data at render time. Inline Alpine components in the PHP views use same-origin JSON/FormData requests, display backend-confirmed results and poll actual queue states. Shared request, feedback and form components are rendered inline from `Resources/Views/components/alpine.php`.

`DocumentStorage` stores originals and generated files in private Google Cloud Storage. `DirectUploadService` reserves an account-scoped object and signs a create-only PUT with its exact MIME type and byte length. The browser transfers the file directly to Google, then finalizes its reservation using metadata only. Completion pins the cloud generation, creates one document record idempotently, and queues `process_document` for format validation and extraction. A document's driver, bucket, object name and generation live in MySQL. The download controller checks ownership and redirects to a five-minute signed GET; browser views and downloads do not stream through PHP. Existing local records remain readable during migration, and local storage is retained as a fixture/compatibility driver.

The document view uses a locally served PDF.js worker and canvas/text layers instead of the browser's embedded PDF interface. Its inline Alpine component follows the authenticated redirect with same-origin credentials and no referrer, then renders the cloud response in the browser. The document workspace is bounded by the viewport; default scale fits each whole page by both width and height. Multi-page scrolling stays inside the PDF, and metadata scrolls in a separate panel. Pages render near the reading position; offscreen canvases are released. Zoom, fit, page navigation and an expanded modal remain in compact bottom controls. Expanded mode makes the background inert, traps keyboard focus and restores focus on exit. Loading failures offer retry and the original download.

Processing uses bounded cloud reads; DOCX extraction and native generation use private temporary files removed in `finally` blocks. Cloud processing status prevents unvalidated documents from being used as email or rendering input. Preserve mode uses `LayoutPreparer`, `SourceExporter` and a server-only `RendererClient` to edit the original DOCX package through the HTTPS API of the separate `Synapse-document-renderer` repository. LibreOffice converts DOCX; Playwright prints HTML when there is no template. PDF CVs are copied unchanged. Source IDs and exact paragraph edits are recorded in CV snapshots, allowing faithful re-export. The text-only letter editor is unavailable for a preserved template. DOCX PDF previews are private derived versions. The simple layout remains an explicit setting and uses the existing native DOCX/Dompdf exporter.

The renderer has no database, user sessions, cloud storage or AI credentials. It authenticates calls with a private bearer token, validates ZIP/XML and paragraph targets, uses private per-request temporary directories and rejects edits that increase page count. Its separate repository lives at `../Synapse-document-renderer` in the local projects folder. Cloud Run builds that repository alone. There is no source import, symlink or Git submodule dependency between the applications; Synapse uses `DOCUMENT_RENDERER_URL` and `DOCUMENT_RENDERER_TOKEN` at runtime. Missing configuration stops preserve-mode generation without a fallback; review and approval requirements remain in PHP. See the sibling checkout's [renderer contract and deployment instructions](../../Synapse-document-renderer/README.md).

The relational model includes users/profiles, career records, documents/CV snapshots, jobs/sources/requirements/matches, applications/letters, providers/usage, Gmail connections/correspondence, settings/runs/tasks, notifications/activity, sessions/remember tokens and request limits. Foreign keys preserve associations. User IDs scope records in preparation for future account administration.

Profile revisions increment on each save. A job/profile edit invalidates match results and review approvals. AI preparation selects only existing career entry indices; unsupported or duplicate indices fail. Original facts are retained. Generated summaries and cover letters remain drafts requiring review.

Queue reservation uses a transaction and `SKIP LOCKED`; per-user/task named locks prevent overlapping work. Retry backoff is bounded. Side-effectful send tasks have one attempt, and an expired send lease moves any submitting application to external review. A unique application per user/job plus a persistent send key protects against repeated submission. Per-user locking serialises daily-send-limit checks.

`ManualJobAlertService` registers strong eligible non-email opportunities in `manual_job_alerts`, unique by account/job. Automatic mode, paid access, match threshold, expiry and unsubmitted state are checked when selecting jobs and again under the user lock before reserving delivery. `manual_job_alert` tasks use PHPMailer with server SMTP credentials and the account's own email. A durable sending reservation prevents repeat delivery; expired leases and unconfirmed transport results require reconciliation. Alert delivery never changes application status, send keys or daily counts. Approved email application tasks are queued separately in descending match order, so manual jobs and absent SMTP credentials cannot consume an application slot.

The scheduler is a short-lived CLI command. It queues due tasks and exits. The worker runs continuously or processes one task. Browser sessions are not required. A private heartbeat file reports recent worker activity; actual task/run timestamps provide the audit trail.

## Adding functionality

- Add ordered migrations; never edit an applied migration to change production schema.
- Add repository methods for new SQL; keep controllers and services free of ad hoc queries.
- Add a route and request validation, plus an actual working UI or documented API.
- For a new external provider, implement an interface and explicitly declare its permission/credential requirements.
- Test ownership, failure behaviour and side effects. Use only `_test` databases and temporary records.


## SaaS accounts

`AccountRepository` stores resumable onboarding, selected career fields/sites, memberships, private site configuration and CV checks. `OnboardingService` validates connected sources for each field and configures per-user collectors and personal or managed AI connections. `Entitlements` checks paid and BYOK capabilities; API handlers and background services enforce the same rules.

`CvReviewService` provides a deterministic first structure/field check and paid schema-validated AI suggestions. `MpesaService` initiates fixed-price STK payments and independently queries Daraja before `PaymentRepository` transactionally activates/renews membership. Google identity and Gmail permissions use separate flows.

Apache serves checked-in CSS/fonts/icons and the pinned Alpine CSP runtime. Application JavaScript is inline in the PHP views, with page-level Alpine state and declarative event bindings. Per-request script nonces permit these scripts without `unsafe-inline` or `unsafe-eval`. Tailwind CLI is the only frontend compilation step. There is no Node application server.
