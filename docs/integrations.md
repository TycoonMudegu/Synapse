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

Supported adapters are `rss` (RSS/Atom) and `json` (normalised jobs or JSON Feed). Confirm the source's permission/terms before enabling collection. Source tests parse the actual response, check robots directives and disable failed sources. Outbound requests use HTTPS, pinned public DNS addresses, certificate verification, response/time limits and no redirects. Supply the final URL of a redirecting feed. Sources are checked no more than once per minute per user, with configured per-run/daily caps. Ordinary HTTP import limits also apply.

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
