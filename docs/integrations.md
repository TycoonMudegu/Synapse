# Integrations

## AI providers

Settings → AI providers accepts an HTTPS OpenAI-compatible **base** URL. Synapse appends `/chat/completions`. Use the endpoint and model from your provider's current documentation; do not put API keys in the URL. API URLs reject embedded credentials, query strings, fragments, private networks and unsafe ports.

Enter current USD input/output prices per million tokens. Configure maximum output tokens, timeout, optional temperature, JSON mode and either `max_tokens` or `max_completion_tokens` according to model support. JSON object mode works with providers that do not implement strict JSON Schema; Synapse still validates every returned object locally. Unsupported parameters fail visibly and can be corrected in settings.

Saving a provider resets its health to untested. An empty key when editing retains the encrypted stored key; it is never rendered back into the form. Select screening, tailoring and cover-letter assignments, set a monthly budget, save and use **Test connection**. The test is a real billable model request with recorded usage. Fallback applies to failed non-vision tasks and keeps reservations for both attempts.

Images require a model that supports OpenAI-style image content blocks. PDF posters are parsed locally first; scanned PDFs without text should be uploaded as images or transcribed. Vision token reservations can be adjusted for the chosen model. Provider names are labels; protocol compatibility, model availability and pricing must be confirmed with the provider.

Primary references:

- [OpenAI structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=chat)
- [Gemini OpenAI compatibility](https://ai.google.dev/gemini-api/docs/openai)

## Google Cloud document storage

Set `DOCUMENT_STORAGE=gcs`, `GOOGLE_APPLICATION_CREDENTIALS` to the service-account JSON path, `GOOGLE_CLOUD_PROJECT`, and `GCS_BUCKET`. The current installation uses the private `synapse_ke` bucket in `jobflow-515507`. The supplied key remains at its original project-root path; it is not embedded in browser code or copied into object metadata.

Run migration `009_cloud_documents.sql` through `php scripts/migrate.php`. `php scripts/configure-cloud-storage.php --apply` sets CORS for the origin of `APP_URL` and localhost; `GCS_CORS_ORIGINS` adds explicit origins. The bucket must enforce uniform access and public access prevention. Signed PUT uploads expire after 15 minutes and bind the MIME type, exact byte length, and a generation-zero precondition. Finalization reads cloud metadata only and queues validation/extraction. Authenticated viewing and download endpoints return five-minute signed GET redirects pinned to the stored generation. Browser transfers go directly to `storage.googleapis.com`; document processing and email composition still read the cloud objects as needed.

`scripts/migrate-document-storage.php --apply` verifies uploaded checksums, switches references and writes a private recovery manifest while retaining the originals. Run it with access to the original files and manifest directory. `scripts/remove-migrated-document-copies.php` previews separately approved local cleanup and rechecks cloud checksums and database references before deletion. Database metadata and extracted text remain in MySQL; object storage holds the document files.

Primary references: [signed URLs](https://cloud.google.com/storage/docs/access-control/signed-urls), [browser CORS](https://cloud.google.com/storage/docs/cors-configurations), and [PHP storage objects](https://cloud.google.com/php/docs/reference/cloud-storage/latest/StorageObject).

## Document rendering

The optional external renderer preserves the uploaded DOCX design in tailored documents and PDF previews. Deploy the separate [renderer repository](../../Synapse-document-renderer/README.md), then set its HTTPS origin and matching secret in `DOCUMENT_RENDERER_URL` and `DOCUMENT_RENDERER_TOKEN`. Only the PHP server receives the token; no browser SDK, credentials, database or bucket are required in the renderer.

The service inspects exact DOCX paragraph targets, edits their text without rebuilding the document, converts with LibreOffice and checks page growth. Playwright handles HTML/CSS printing when no template exists. It cannot edit an uploaded PDF: PDF CVs are retained unchanged and PDF letter templates require an editable DOCX upload. Keep source fonts available and review the rendered PDF because LibreOffice and Word can differ. Missing setup stops preserve mode with an actionable message. The explicit simple layout setting retains the existing local exporter.

## Google OAuth and Gmail

1. Create a Google Cloud project and enable the Gmail API.
2. Configure an OAuth consent screen. In testing mode, add your account as a test user. For a broader audience, complete Google's applicable verification requirements.
3. Create a **Web application** OAuth client. Add the exact callback `https://your-host/Synapse/Callback` to its authorised redirect URIs, adjusting the installation path. This workspace uses `http://localhost/Synapse/Callback` (capital C). The scheme, host, port, path and case must match Google's registered URL.
4. Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` and `APP_URL` in the server environment. Alternatively, set `GOOGLE_OAUTH_JSON` to the absolute server-side path of the downloaded Web application JSON, or its contents on one single-quoted line; separate client ID/secret variables take precedence. Set both `GOOGLE_REDIRECT_URI` and `GOOGLE_SIGNIN_REDIRECT_URI` to the registered callback. Server-side state selects the correct flow; Gmail permissions remain separate from basic identity sign-in. Existing installations may keep `/oauth/google/callback` if that URL is separately registered. The client secret remains server-side.
5. Save Gmail preferences in Synapse. Sending requests `openid`, `email` and `gmail.send`. Enabling alert monitoring or response tracking also requests `gmail.readonly`.
6. Choose **Connect Gmail** and select the sending account. Synapse checks OAuth state, expiry, signed-in user, granted send scope and the verified Google identity. Tokens are encrypted with `APP_KEY`.
7. If adding read permissions later, save the new preferences and reconnect. Reconnect is also how you select a different sending account.
8. Send a test email to the connected account. Synapse only reports success when Google returns a message ID.

Monitoring queries should target specific senders or a dedicated Gmail label, for example `label:job-alerts`. Alerts are restricted to the last 30 days, 50 per synchronisation. Application responses are fetched only from thread IDs recorded after Synapse submissions, up to 50 recent application threads per sync. HTML email is reduced to escaped text. Imported messages are deduplicated by Gmail ID. No email is deleted, marked read, moved or otherwise modified.

Disconnect removes locally stored tokens and attempts Google revocation. If revocation fails, the interface explains how to remove access in Google account permissions.

Gmail sends are multipart MIME with the reviewed CV and letter attached and a stable Message-ID. Confirmed Gmail message/thread IDs are recorded. Network ambiguity cannot prove non-delivery, so such sends remain for manual reconciliation and are not retried automatically.

Primary references:

- [Google web-server OAuth](https://developers.google.com/identity/protocols/oauth2/web-server)
- [Gmail sending messages](https://developers.google.com/workspace/gmail/api/guides/sending)
- [Gmail scopes](https://developers.google.com/workspace/gmail/api/auth/scopes)

## Job feeds and APIs

Supported adapters are `rss` (RSS/Atom), `json` (normalised jobs or JSON Feed), `myjobmag` (official detailed RSS), `brightermonday_public`, `corporatestaffing_public` and `talent_public` (public technical job pages), `fuzu_public` (public technical job pages rendered in Playwright Chromium), `google_jobs_api` and `indeed_api` (SerpApi Google Jobs results), and optional `email_alert` (linked vacancies in subscribed BrighterMonday/Fuzu emails). Source tests parse the actual response, check robots directives for public pages and disable failed sources. Outbound requests use HTTPS, pinned public DNS addresses, certificate verification, page/time limits and no redirects. Fuzu's isolated browser permits only Fuzu and its image asset host; Chromium blocks redirect responses before following them. Supply the final URL of a redirecting feed. Discovery defers sources checked in the last minute and enforces configured per-run/daily caps. Ordinary HTTP import limits also apply.

Normalised JSON feeds can avoid AI extraction by returning objects with `title`, `employer` and `description`. Missing fields remain unknown. The following is a **schema illustration**, not bundled vacancy data:

```json
{
    "jobs": [
        {
            "title": "<advertised title>",
            "employer": "<advertised employer>",
            "description": "<original description>",
            "location": "<advertised location>",
            "employment_type": "not_specified",
            "workplace": "not_specified",
            "application_method": "manual",
            "original_url": "https://source.example/original-vacancy"
        }
    ]
}
```

Optional fields follow the manual-job form: requirements, qualifications, responsibilities, deadline (`YYYY-MM-DD`), salary bounds/currency, application email, required email subject and instructions. Unknown scalar text should be empty; unknown numeric/date values should be omitted or null. JSON Feed `items` and RSS/Atom entries are extracted with the configured screening AI provider. Search keyword/category/exclusion, location, work-arrangement, salary and expiry preferences are applied before persistence.

Deduplication always applies, regardless of the source. The original source remains linked; removing a source retains its saved jobs. Extracted jobs are unverified until the user checks the original instructions.

## Extending adapters

Implement `App\Services\Discovery\SourceAdapter` for an authorised job source and register it in `DiscoveryService::adapter`. Keep rate limits, permissions, normalisation, health/error reporting and collection caps intact.

Implement `App\Services\AI\Provider` for a non-compatible AI protocol and inject/select the implementation in `AiService`. Keep schema validation, budget reservations, prompt isolation and encrypted credentials intact.

`App\Services\Applications\SubmissionAdapter` defines the confirmed-submission boundary. Gmail is the supplied implementation. A future recruitment API adapter must have explicit provider authorisation and enforce the same factual verification, approval, eligibility, concurrency, daily limits and confirmation rules as `ApplicationService`. Do not mark an external browser form submitted merely because it was opened or prefilled. CAPTCHAs, OTPs and declarations always require user completion.


## Built-in job sources

Settings → Job discovery and the Discover page include seven built-in sources. Add one or choose **Add all sources** through the real configuration action. Repeated additions retain the same source and do not re-enable a source you disabled. No database migration is needed for these additional presets/settings.

- **MyJobMag Kenya:** imports `https://www.myjobmag.co.ke/jobsxml_by_categories.xml`, advertised on [MyJobMag's official feed page](https://www.myjobmag.co.ke/feeds/). Explicit position, company, location and expiry fields are used directly. No AI key is needed; salary and other absent facts stay unknown. The adapter checks robots/TLS/SSRF safeguards and never requests the job-page links in the feed. Kenya location preferences recognise Kenyan cities/counties without changing the original location text.
- **BrighterMonday Kenya:** defaults to the public [Software & Data category](https://www.brightermonday.co.ke/jobs/software-data), collecting same-site `/listings/` detail pages. JSON-LD `JobPosting` records and referenced employer/address nodes supply factual fields. This connection has been live-verified from this workspace.
- **Fuzu:** opens [the public job search](https://www.fuzu.com/kenya/job), then the [IT and software category](https://www.fuzu.com/kenya/job/computers-software-development) and same-site `/kenya/jobs/` detail links in one Playwright Chromium context. JavaScript renders normally, and cookies stay within that collection run. Explicit JSON-LD vacancy fields go through the same parser, saved filters and deduplication as other public jobs. Visible Chromium successfully collected live vacancies from this host on 16 November 2026; HTTP and headless Chromium returned HTTP 403. No login or Gmail connection is needed.
- **Corporate Staffing Kenya:** reads the supplied [IT jobs category](https://www.corporatestaffing.co.ke/category/it-jobs-in-kenya/) and same-host `/job/<slug>/` links through ordinary HTTP. Canonical trailing slashes are retained to avoid WordPress redirects. JSON-LD `JobPosting` supplies employer, title, description and any explicit location, employment type and deadline. A live read-only check on 16 November 2026 parsed three vacancies, including Head of ICT at Mhasibu SACCO, Assistant ICT Officer at University of Embu and Cyber Security Engineer at Onfon Mobile. No browser, Gmail or AI key is needed. Incomplete pages are skipped; missing fields are not inferred.
- **Google Jobs:** imports structured search results from the third-party [SerpApi Google Jobs API](https://serpapi.com/google-jobs-api). This is not a Google-provided API. [Google's robots file](https://www.google.com/robots.txt) disallows `/search`, and the app's existing public-page checks remain intact. This adapter does not request Google search, LinkedIn or employer application pages. It uses only SerpApi's fixed HTTPS endpoint and stays inactive until configured and checked. A live API check has not been performed because no SerpApi key was provided.
- **Talent.com Kenya:** reads the public [software developer search](https://ke.talent.com/jobs/k-software-developer-l-kenya) and same-host `/view?id=<numeric-id>` vacancy pages through ordinary HTTP. Its robots rules allow these public pages and exclude API and application-redirect routes, which this collector never requests. The parser checks the page's canonical job ID, then reads one primary job article's explicit title, employer, location and full description. Related-job cards are excluded; missing or ambiguous fields fail validation. No browser, Gmail or API key is needed. Unknown dedicated salary/deadline/work-arrangement fields stay unknown. Relative update labels do not become deadlines. Saved role/location/expiry filters and duplicate checks apply. A read-only live check parsed three vacancies on 16 November 2026.
- **Indeed via Google Jobs:** uses the same SerpApi key, query and location as Google Jobs. It retains only results carrying a valid `https://<indeed.com host>/viewjob?jk=<16-character job key>` source or application link; an Indeed provider label alone does not qualify. Direct Indeed, employer and tracking pages are never fetched. Coverage is limited to Google's indexed vacancies. On this host `ke.indeed.com` did not resolve and the ordinary `www.indeed.com` search returned HTTP 403; [Indeed's robots rules](https://www.indeed.com/robots.txt) also exclude `/viewjob`. SerpApi's dedicated [Indeed Jobs API](https://serpapi.com/indeed-jobs-api) documents US search coverage, so the Kenya-focused preset uses Google Jobs results. The connection is fixture-tested; a live API check needs your SerpApi key.

For Google Jobs and Indeed, open **Settings → Job discovery → Google Jobs connection**, save your SerpApi key, search query and location, then add the desired source cards and choose **Check API** for each. The defaults are `IT jobs in Kenya` and `Kenya`. Keys are encrypted with the installation's `APP_KEY` and never rendered back into the form; leaving the field blank keeps the existing key. Changing the query/location/key or removing the key disables both sources until each is checked again. Settings and credentials are isolated per account; removing a key retains saved jobs.

Each Google Jobs/Indeed connection check makes at most one search request; each source collection makes at most two and keeps at most 20 complete, distinct results. Requests count against your SerpApi account quota and charges. Pagination uses tokens with the fixed endpoint, never provider-supplied next URLs. Title, company and description are required; unknown salary/deadline stay unknown, and relative posting dates are not treated as deadlines. Google Jobs keeps an explicit source/application URL, with a vacancy-specific Google share link as a fallback. Indeed requires an explicit Indeed vacancy URL and keeps only its stable job key. Only result data is imported; these links open for user review and are never fetched by this adapter. Saved discovery preferences and normal deduplication apply, including fingerprints across sources. Errors expose no API keys or raw provider responses.

Public mode needs no Gmail connection or AI provider. Click **Check public pages** to validate a source and enable it only after a real vacancy parses successfully. Each run reads one listing page (plus Fuzu's entry page) and at most 20 detail pages sequentially with a one-second delay between pages; robots are fetched once per host per collection. Search endpoints, login pages, off-site vacancy links and redirects are excluded; Talent detail requests allow only its canonical numeric `id` query. Each complete vacancy passes the saved role/location/expiry filters and normal deduplication before insertion. Already saved structured vacancies do not consume the per-run processing allowance. Unknown salary and work arrangement remain unknown; jobs are unverified and application actions open the original website. Removed, incomplete or temporarily failing detail pages are skipped and counted in the PHP log. Collection stops after three consecutive HTTP 500/502/503/504 detail failures, preserving any complete vacancies already read; if none were read before that stop, the server error is reported. HTTP 403/429 stops the source with an actionable error. Fuzu uses a fresh, temporary browser profile and closes it at the end; no stored account cookies, CAPTCHA solving, proxy rotation or browser fingerprint changes are used.

Install Fuzu's runtime dependencies as the same operating-system account that runs PHP and the worker:

```bash
npm ci --omit=dev
npx playwright install --with-deps chromium
php scripts/collect-fuzu.php 2
```

The last command is a read-only live check that prints up to two parsed vacancies; it does not require a database connection or change saved jobs/source settings. `playwright` is a production dependency. Browser installation may require an administrator to install system libraries. The default `FUZU_BROWSER_HEADLESS=false` opens a visible browser, so PHP and the worker need a working display (`DISPLAY` on Linux). A server can provide a virtual display; headless operation can be selected explicitly with `FUZU_BROWSER_HEADLESS=true`, but was blocked by Fuzu on this host. Set `NODE_BINARY` to an absolute Node.js path for service accounts if needed; `CHROMIUM_PATH` optionally selects an installed full Chromium binary. Missing browsers, display failures, security-check pages and HTTP failures appear in the source error. Existing `fuzu_public` presets use this transport without a migration or mode change; recheck a previously disabled source to enable it after a real successful collection.

Use **Use email alerts** to opt into the previous email path, or **Use public pages** to return to direct collection. Changing modes retains saved jobs and pending alert records. Existing installations keep their selected modes when presets are re-added; new presets default to public mode. A public-source error is never replaced by a Gmail connection requirement.

For optional email mode, enable the website's own alerts using the Gmail address connected in Synapse. Synapse requests read-only monitoring and searches only the selected email sources' sender domains, plus any separate user query you explicitly configure. Actual sender addresses are checked after retrieval. Direct vacancy links are extracted from plain-text or HTML email, canonicalised and deduplicated by source and URL. A multi-job email creates separate pending vacancy items. Messages without recognised links or a job-alert subject are excluded when using only the built-in source queries. Job-alert subjects with unsupported tracking links are saved for manual review.

Enable read-only monitoring and connect/reconnect Gmail in Settings → Gmail. A source becomes connected only after a successful Gmail API query. Without Gmail it remains Needs connection; missing read permission is shown separately. Disconnecting Gmail invalidates the email-source health while retaining your saved monitoring preference.

A configured AI screening provider structures each linked vacancy using its specific target URL and the received advertisement text. No employer page is fetched through this adapter, no missing facts are fabricated and the resulting jobs remain unverified. Missing provider configuration or unclear details are recorded against the pending alert item; use **Retry failed imports** after correcting configuration. Unsupported tracking-only links stay in the inbox for manual review. Parsed email is always untrusted input.

The website itself sends the subscribed alerts. Adding a preset does not create an external account, subscribe it automatically or claim email delivery. In-app discovery notifications are real database records; outbound job-digest emails are not part of this source addition.

For an existing account, the CLI installation flow is:

```bash
php scripts/migrate.php
php scripts/add-job-sources.php <user-id> --public
```

This adds all seven presets, switches BrighterMonday/Fuzu to public mode, checks each source, enables scheduled discovery and queues collection from successfully connected sources. Corporate Staffing and Talent always use public mode; Google Jobs and Indeed require the account's saved SerpApi key. Omit `--public` to retain existing BrighterMonday/Fuzu collection modes. It preserves application submission mode and does not unpause a deliberately paused workspace.
