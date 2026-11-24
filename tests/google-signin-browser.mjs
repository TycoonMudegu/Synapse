import { chromium, expect } from '@playwright/test';
import { spawn, execFileSync } from 'node:child_process';
import { mkdir, writeFile, rm } from 'node:fs/promises';
import { resolve } from 'node:path';

const base = 'http://127.0.0.1:8854';
const testDatabase = process.env.JOBFLOW_TEST_DATABASE ?? 'jobflow_test';
if (!/^[a-zA-Z0-9_]+_test$/.test(testDatabase))
    throw new Error('Use a separate database ending in _test.');
const clientId = 'fixture-google.apps.googleusercontent.com';
const clientSecret = 'fixture-google-secret-never-live';
const credentials = {
    web: {
        client_id: clientId,
        client_secret: clientSecret,
        auth_uri: 'https://example.test/untrusted-authorize',
        redirect_uris: ['https://example.test/untrusted-callback'],
    },
};
await mkdir('tests/.runtime', { recursive: true });
const jsonPath = resolve('tests/.runtime/google-oauth-client.json');
await writeFile(jsonPath, JSON.stringify(credentials), { mode: 0o600 });
const env = {
    ...process.env,
    DB_DATABASE: testDatabase,
    APP_URL: base,
    DOCUMENT_STORAGE: process.env.JOBFLOW_TEST_STORAGE || 'local',
    SESSION_SECURE: 'false',
    GOOGLE_CLIENT_ID: '',
    GOOGLE_CLIENT_SECRET: '',
    GOOGLE_OAUTH_JSON: jsonPath,
    GOOGLE_SIGNIN_REDIRECT_URI: `${base}/Callback`,
};
const server = spawn('php', ['-S', '127.0.0.1:8854', '-t', 'Public', 'Public/router.php'], {
    env,
    stdio: 'ignore',
});
let browser;
let assertions = 0;
const pass = (message) => {
    assertions++;
    console.log(`PASS ${message}`);
};
try {
    const configuration = (overrides) =>
        JSON.parse(
            execFileSync(
                'php',
                [
                    '-r',
                    'require "Bootstrap/App.php"; echo json_encode([App\\Services\\Security\\GoogleOAuthConfig::clientId(), App\\Services\\Security\\GoogleOAuthConfig::clientSecret()]);',
                ],
                { env: { ...env, ...overrides }, encoding: 'utf8' },
            ),
        );
    expect(configuration({})).toEqual([clientId, clientSecret]);
    expect(configuration({ GOOGLE_OAUTH_JSON: JSON.stringify(credentials) })).toEqual([
        clientId,
        clientSecret,
    ]);
    expect(
        configuration({ GOOGLE_CLIENT_ID: 'explicit-id', GOOGLE_CLIENT_SECRET: 'explicit-secret' }),
    ).toEqual(['explicit-id', 'explicit-secret']);
    expect(configuration({ GOOGLE_OAUTH_JSON: `${jsonPath}.missing` })).toEqual(['', '']);
    expect(configuration({ GOOGLE_OAUTH_JSON: '{invalid-json}' })).toEqual(['', '']);
    pass(
        'downloaded JSON paths, inline JSON, explicit overrides and unavailable credentials behave correctly',
    );

    let ready = false;
    for (let i = 0; i < 40; i++) {
        try {
            if ((await fetch(`${base}/register`)).status === 200) {
                ready = true;
                break;
            }
        } catch {}
        await new Promise((resolve) => setTimeout(resolve, 200));
    }
    expect(ready).toBe(true);
    browser = await chromium.launch({
        executablePath: process.env.CHROMIUM_PATH || chromium.executablePath(),
        args: ['--no-sandbox'],
    });
    for (const javaScriptEnabled of [true, false]) {
        const context = await browser.newContext({ javaScriptEnabled });
        try {
            const page = await context.newPage();
            const violations = [];
            page.on('console', (message) => {
                if (message.type() === 'error') violations.push(message.text());
            });
            let authorization;
            let externalRequested = false;
            // Playwright routing only handles the first URL in a redirect chain.
            // Intercept each browser request so the real POST/303 is exercised offline.
            const network = await context.newCDPSession(page);
            network.on('Fetch.requestPaused', async ({ requestId, request }) => {
                const url = new URL(request.url);
                if (url.origin === 'https://accounts.google.com') {
                    if (url.pathname === '/o/oauth2/v2/auth') authorization = url;
                    await network.send('Fetch.fulfillRequest', {
                        requestId,
                        responseCode: 200,
                        responseHeaders: [{ name: 'Content-Type', value: 'text/html' }],
                        body: Buffer.from('<h1>Google account chooser fixture</h1>').toString(
                            'base64',
                        ),
                    });
                } else {
                    externalRequested = true;
                    await network.send('Fetch.failRequest', {
                        requestId,
                        errorReason: 'BlockedByClient',
                    });
                }
            });
            await network.send('Fetch.enable', {
                patterns: [{ urlPattern: 'https://*', requestStage: 'Request' }],
            });
            for (const path of ['/register', '/login']) {
                authorization = undefined;
                const response = await page.goto(`${base}${path}`);
                expect(response.headers()['content-security-policy']).toContain(
                    "form-action 'self' https://accounts.google.com",
                );
                expect(await page.content()).not.toContain(clientSecret);
                await expect(
                    page.getByRole('button', { name: 'Continue with Google' }),
                ).toBeEnabled();
                await page.getByRole('button', { name: 'Continue with Google' }).click();
                await expect(
                    page.getByRole('heading', { name: 'Google account chooser fixture' }),
                ).toBeVisible();
                expect(authorization.origin).toBe('https://accounts.google.com');
                expect(authorization.pathname).toBe('/o/oauth2/v2/auth');
                expect(authorization.searchParams.get('client_id')).toBe(clientId);
                expect(authorization.searchParams.get('redirect_uri')).toBe(`${base}/Callback`);
                expect(authorization.searchParams.get('scope')).toBe('openid email profile');
                expect(authorization.searchParams.get('state')).toMatch(/^[a-f0-9]{64}$/);
                expect(authorization.searchParams.get('code_challenge_method')).toBe('S256');
                expect(authorization.searchParams.get('code_challenge')).toMatch(
                    /^[A-Za-z0-9_-]{43}$/,
                );
                expect(authorization.searchParams.has('client_secret')).toBe(false);
                expect(violations).toEqual([]);
                pass(
                    `${path} opens Google through its native POST with JavaScript ${javaScriptEnabled ? 'enabled' : 'disabled'}`,
                );
            }
            if (javaScriptEnabled) {
                const denied = await context.request.post(`${base}/auth/google`, {
                    form: {},
                    maxRedirects: 0,
                });
                expect(denied.status()).toBe(403);
                pass('Google sign-in still rejects a missing CSRF token');

                await page.goto(
                    `${base}/Callback?error=access_denied&state=${authorization.searchParams.get('state')}`,
                );
                await expect(
                    page.getByText('Google sign-in expired or was cancelled. Please try again.', {
                        exact: true,
                    }),
                ).toBeVisible();
                await expect(
                    page.getByRole('button', { name: 'Continue with Google' }),
                ).toBeEnabled();
                pass('the capital-C callback returns a useful retry message after cancellation');

                const blocked = page.waitForEvent('console', {
                    predicate: (message) =>
                        message.type() === 'error' && message.text().includes('form-action'),
                });
                await page.evaluate(() => {
                    const form = document.createElement('form');
                    form.action = 'https://example.test/blocked';
                    form.method = 'post';
                    document.body.append(form);
                    form.submit();
                });
                await blocked;
                expect(externalRequested).toBe(false);
                expect(new URL(page.url()).origin).toBe(base);
                pass('unrelated external form destinations remain blocked by the browser');
            }
        } finally {
            await context.close();
        }
    }
    console.log(`${assertions} Google sign-in checks passed.`);
} finally {
    await browser?.close();
    server.kill();
    await rm(jsonPath, { force: true });
}
