# Integrations

## AI providers

Settings → AI providers accepts an HTTPS OpenAI-compatible **base** URL. JobFlow appends `/chat/completions`. Use the endpoint and model from your provider's current documentation; do not put API keys in the URL. API URLs reject embedded credentials, query strings, fragments, private networks and unsafe ports.

Enter current USD input/output prices per million tokens. Configure maximum output tokens, timeout, optional temperature, JSON mode and either `max_tokens` or `max_completion_tokens` according to model support. JSON object mode works with providers that do not implement strict JSON Schema; JobFlow still validates every returned object locally. Unsupported parameters fail visibly and can be corrected in settings.

Saving a provider resets its health to untested. An empty key when editing retains the encrypted stored key; it is never rendered back into the form. Select screening, tailoring and cover-letter assignments, set a monthly budget, save and use **Test connection**. The test is a real billable model request with recorded usage. Fallback applies to failed non-vision tasks and keeps reservations for both attempts.

Images require a model that supports OpenAI-style image content blocks. PDF posters are parsed locally first; scanned PDFs without text should be uploaded as images or transcribed. Vision token reservations can be adjusted for the chosen model. Provider names are labels; protocol compatibility, model availability and pricing must be confirmed with the provider.

Primary references:

- [OpenAI structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs?api-mode=chat)
- [Gemini OpenAI compatibility](https://ai.google.dev/gemini-api/docs/openai)

## Google OAuth and Gmail

1. Create a Google Cloud project and enable the Gmail API.
2. Configure an OAuth consent screen. In testing mode, add your account as a test user. For a broader audience, complete Google's applicable verification requirements.
3. Create a **Web application** OAuth client. Add the exact callback `https://your-host/oauth/google/callback` to its authorised redirect URIs. For local testing, Google supports a loopback callback configured explicitly in the client.
4. Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI` and `APP_URL` in the server environment. The client secret remains server-side.
5. Save Gmail preferences in JobFlow. Sending requests `openid`, `email` and `gmail.send`. Enabling alert monitoring or response tracking also requests `gmail.readonly`.
6. Choose **Connect Gmail** and select the sending account. JobFlow checks OAuth state, expiry, signed-in user, granted send scope and the verified Google identity. Tokens are encrypted with `APP_KEY`.
7. If adding read permissions later, save the new preferences and reconnect. Reconnect is also how you select a different sending account.
8. Send a test email to the connected account. JobFlow only reports success when Google returns a message ID.

Monitoring queries should target specific senders or a dedicated Gmail label, for example `label:job-alerts`. Alerts are restricted to the last 30 days, 50 per synchronisation. Application responses are fetched only from thread IDs recorded after JobFlow submissions, up to 50 recent application threads per sync. HTML email is reduced to escaped text. Imported messages are deduplicated by Gmail ID. No email is deleted, marked read, moved or otherwise modified.

Disconnect removes locally stored tokens and attempts Google revocation. If revocation fails, the interface explains how to remove access in Google account permissions.

Gmail sends are multipart MIME with the reviewed CV and letter attached and a stable Message-ID. Confirmed Gmail message/thread IDs are recorded. Network ambiguity cannot prove non-delivery, so such sends remain for manual reconciliation and are not retried automatically.

Primary references:

- [Google web-server OAuth](https://developers.google.com/identity/protocols/oauth2/web-server)
- [Gmail sending messages](https://developers.google.com/workspace/gmail/api/guides/sending)
- [Gmail scopes](https://developers.google.com/workspace/gmail/api/auth/scopes)

## Job feeds and APIs

Supported adapters are `rss` (RSS/Atom), `json` (normalised jobs or JSON Feed), `myjobmag` (official detailed RSS), `brightermonday_public` and `fuzu_public` (public technical job pages), and optional `email_alert` (linked vacancies in subscribed BrighterMonday/Fuzu emails). Source tests parse the actual response, check robots directives and disable failed sources. Outbound requests use HTTPS, pinned public DNS addresses, certificate verification, response/time limits and no redirects. Supply the final URL of a redirecting feed. Sources are checked no more than once per minute per user, with configured per-run/daily caps. Ordinary HTTP import limits also apply.

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


## BrighterMonday, Fuzu and MyJobMag presets

Settings → Job discovery and the Discover page include all three built-in sources. Add one or all three through the real configuration action. Repeated additions retain the same source and do not re-enable a source you disabled.

- **MyJobMag Kenya:** imports `https://www.myjobmag.co.ke/jobsxml_by_categories.xml`, advertised on [MyJobMag's official feed page](https://www.myjobmag.co.ke/feeds/). Explicit position, company, location and expiry fields are used directly. No AI key is needed; salary and other absent facts stay unknown. The adapter checks robots/TLS/SSRF safeguards and never requests the job-page links in the feed. Kenya location preferences recognise Kenyan cities/counties without changing the original location text.
- **BrighterMonday Kenya:** defaults to the public [Software & Data category](https://www.brightermonday.co.ke/jobs/software-data), collecting same-site `/listings/` detail pages. JSON-LD `JobPosting` records and referenced employer/address nodes supply factual fields. This connection has been live-verified from this workspace.
- **Fuzu:** defaults to the public [IT and software category](https://www.fuzu.com/kenya/job/computers-software-development), with same-site `/kenya/jobs/` detail links and structured vacancy parsing. Fuzu currently returns HTTP 403, including with an ordinary browser, from this server. Its source shows the actual failure and remains inactive. The parser is fixture-tested; successful live Fuzu collection has not been verified.

Public mode needs no Gmail connection or AI provider. Click **Check public pages** to validate a source and enable it only after a real vacancy parses successfully. Each run reads one category page and at most 20 detail pages sequentially with a one-second delay between detail requests; robots are fetched once per host per collection. Query-based search endpoints, login pages, off-site links and redirects are excluded. Each complete vacancy passes the saved role/location/expiry filters and normal deduplication before insertion. Already saved structured vacancies do not consume the per-run processing allowance. Unknown salary and work arrangement remain unknown; jobs are unverified and application actions open the original website. Removed or incomplete detail pages are skipped and counted in the PHP log. HTTP 403/429 stops the source with an actionable error; no CAPTCHA, proxy rotation, cookies or browser evasion is supplied.

Use **Use email alerts** to opt into the previous email path, or **Use public pages** to return to direct collection. Changing modes retains saved jobs and pending alert records. Existing installations keep their selected modes when presets are re-added; new presets default to public mode. A public-source error is never replaced by a Gmail connection requirement.

For optional email mode, enable the website's own alerts using the Gmail address connected in JobFlow. JobFlow requests read-only monitoring and searches only the selected email sources' sender domains, plus any separate user query you explicitly configure. Actual sender addresses are checked after retrieval. Direct vacancy links are extracted from plain-text or HTML email, canonicalised and deduplicated by source and URL. A multi-job email creates separate pending vacancy items. Messages without recognised links or a job-alert subject are excluded when using only the built-in source queries. Job-alert subjects with unsupported tracking links are saved for manual review.

Enable read-only monitoring and connect/reconnect Gmail in Settings → Gmail. A source becomes connected only after a successful Gmail API query. Without Gmail it remains Needs connection; missing read permission is shown separately. Disconnecting Gmail invalidates the email-source health while retaining your saved monitoring preference.

A configured AI screening provider structures each linked vacancy using its specific target URL and the received advertisement text. No employer page is fetched through this adapter, no missing facts are fabricated and the resulting jobs remain unverified. Missing provider configuration or unclear details are recorded against the pending alert item; use **Retry failed imports** after correcting configuration. Unsupported tracking-only links stay in the inbox for manual review. Parsed email is always untrusted input.

The website itself sends the subscribed alerts. Adding a preset does not create an external account, subscribe it automatically or claim email delivery. In-app discovery notifications are real database records; outbound job-digest emails are not part of this source addition.

For an existing account, the CLI installation flow is:

```bash
php scripts/migrate.php
php scripts/add-job-sources.php <user-id> --public
```

This adds the three presets, switches BrighterMonday/Fuzu to public mode, tests actual public responses, enables scheduled discovery and queues collection from successfully connected sources. Omit `--public` to retain existing collection modes. It preserves application submission mode and does not unpause a deliberately paused workspace. No new migration is needed for collection modes.
