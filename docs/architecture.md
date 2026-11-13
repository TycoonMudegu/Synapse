# Architecture

`Public/index.php` is the front controller. `Bootstrap/App.php` loads Composer, environment configuration, timezone, sessions and response security headers. `Routes/web.php` declares routes. The lightweight router applies authentication, CSRF and request limits before controllers run.

Controllers validate HTTP intent and invoke services/repositories. Services coordinate profile verification, imports, AI tasks, document generation, discovery, OAuth and application submission. Repositories contain prepared PDO statements and user-scoped queries. Views escape data at render time. JavaScript modules use same-origin JSON/FormData requests, display backend-confirmed results and poll actual queue states.

`storage/private` holds originals and generated files under random identifiers. Documents are streamed through an authenticated, ownership-checked controller. DOCX creation uses native ZIP/XML; parsing checks expanded sizes and does not extract arbitrary ZIP paths. Dompdf renders escaped text without remote resources or PHP execution.

The relational model includes users/profiles, career records, documents/CV snapshots, jobs/sources/requirements/matches, applications/letters, providers/usage, Gmail connections/correspondence, settings/runs/tasks, notifications/activity, sessions/remember tokens and request limits. Foreign keys preserve associations. User IDs scope records in preparation for future account administration.

Profile revisions increment on each save. A job/profile edit invalidates match results and review approvals. AI preparation selects only existing career entry indices; unsupported or duplicate indices fail. Original facts are retained. Generated summaries and cover letters remain drafts requiring review.

Queue reservation uses a transaction and `SKIP LOCKED`; per-user/task named locks prevent overlapping work. Retry backoff is bounded. Side-effectful send tasks have one attempt, and an expired send lease moves any submitting application to external review. A unique application per user/job plus a persistent send key protects against repeated submission. Per-user locking serialises daily-send-limit checks.

The scheduler is a short-lived CLI command. It queues due tasks and exits. The worker runs continuously or processes one task. Browser sessions are not required. A private heartbeat file reports recent worker activity; actual task/run timestamps provide the audit trail.

## Adding functionality

- Add ordered migrations; never edit an applied migration to change production schema.
- Add repository methods for new SQL; keep controllers and services free of ad hoc queries.
- Add a route and request validation, plus an actual working UI or documented API.
- For a new external provider, implement an interface and explicitly declare its permission/credential requirements.
- Test ownership, failure behaviour and side effects. Use only `_test` databases and temporary records.
