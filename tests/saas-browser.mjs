import { chromium, expect } from '@playwright/test';
import { spawn, execFileSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';

const base = 'http://127.0.0.1:8852';
const fixture = JSON.parse(
    execFileSync('php', ['tests/saas-browser-fixtures.php', 'setup'], { encoding: 'utf8' }),
);
const email = `browser-saas-${Date.now()}@example.test`;
const promoEmail = `browser-saas-promo-${Date.now()}@example.test`;
const password = 'browser-test-password-12345';
const server = spawn('php', ['-S', '127.0.0.1:8852', '-t', 'Public', 'Public/router.php'], {
    env: {
        ...process.env,
        DB_DATABASE: process.env.JOBFLOW_TEST_DATABASE ?? 'jobflow_test',
        APP_URL: base,
        DOCUMENT_STORAGE: process.env.JOBFLOW_TEST_STORAGE || 'local',
        SESSION_SECURE: 'false',
        GEMINI_API_KEY: '',
        SMTP_PASSWORD: '',
        GOOGLE_CLIENT_ID: 'fixture-google.apps.googleusercontent.com',
        GOOGLE_CLIENT_SECRET: 'fixture-google-secret-never-live',
        GOOGLE_OAUTH_JSON: '',
        GOOGLE_SIGNIN_REDIRECT_URI: '',
        GOOGLE_REDIRECT_URI: `${base}/Callback`,
    },
    stdio: 'ignore',
});
let browser;
let userId;
let promoUserId;
let assertions = 0;
const pass = (message) => {
    assertions++;
    console.log(`PASS ${message}`);
};
try {
    await mkdir('tests/.runtime', { recursive: true });
    for (let i = 0; i < 40; i++) {
        try {
            if ((await fetch(`${base}/register`)).status === 200) break;
        } catch {}
        await new Promise((resolve) => setTimeout(resolve, 200));
    }
    const executablePath =
        process.env.CHROMIUM_PATH ||
        execFileSync(
            'php',
            ['-r', 'require "Bootstrap/App.php"; echo App\\Core\\Env::get("CHROMIUM_PATH");'],
            { encoding: 'utf8' },
        ).trim() ||
        chromium.executablePath();
    browser = await chromium.launch({ executablePath, args: ['--no-sandbox'] });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(base);
    await expect(page).toHaveURL(`${base}/login`);
    await expect(page.getByRole('button', { name: 'Continue with Google' })).toBeDisabled();
    await page.screenshot({ path: 'tests/.runtime/register-desktop.png', fullPage: true });
    pass('new visitors see registration and unavailable Google sign-in is honest');
    const noCsrf = await context.request.post(`${base}/register`, {
        data: { name: 'Test', email, password, password_confirmation: password },
    });
    expect(noCsrf.status()).toBe(403);
    pass('registration rejects missing CSRF');
    await page.getByLabel('Email address', { exact: true }).fill(email);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Your name', { exact: true }).fill('SaaS Browser');
    await page.getByLabel('Confirm password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page).toHaveURL(`${base}/onboarding`);
    userId = JSON.parse(
        execFileSync('php', ['tests/saas-browser-fixtures.php', 'lookup', email], {
            encoding: 'utf8',
        }),
    ).id;
    pass('email registration creates an account and starts onboarding');
    await page.goto(`${base}/jobs`);
    await expect(page).toHaveURL(`${base}/onboarding`);
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const early = await context.request.post(`${base}/api/onboarding`, {
        headers: { 'X-CSRF-Token': csrf },
        data: { stage: 'automation' },
    });
    expect(early.status()).toBe(422);
    pass('incomplete accounts cannot bypass onboarding or submit later stages');
    await page.getByLabel('Your job field', { exact: true }).selectOption('finance');
    await expect(page.getByText('Talent.com Kenya', { exact: true })).not.toBeVisible();
    await page.getByLabel('Preferred job titles', { exact: false }).fill('Accountant, Finance');
    await page.getByLabel('MyJobMag Kenya', { exact: false }).check();
    await page.screenshot({ path: 'tests/.runtime/onboarding-search-desktop.png', fullPage: true });
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page).toHaveURL(`${base}/onboarding?step=2`);
    await expect(page.getByRole('button', { name: 'Coming soon' })).toBeDisabled();
    pass('field selection filters working sources and document generation stays disabled');
    await page.getByLabel('Upload your CV', { exact: true }).setInputFiles(fixture.path);
    await page.getByLabel('Cover letter template', { exact: false }).setInputFiles(fixture.path);
    await page.getByRole('button', { name: 'Save and continue' }).click();
    await expect(page).toHaveURL(`${base}/onboarding?step=3`, { timeout: 15000 });
    await expect(page.getByText('out of 100', { exact: true })).toBeVisible();
    await expect(
        page.getByText('Checked for Finance & accounting.', { exact: true }),
    ).toBeVisible();
    await page.screenshot({ path: 'tests/.runtime/onboarding-cv-desktop.png', fullPage: true });
    pass('private CV and letter uploads produce a field-specific grade');
    await page.getByRole('link', { name: 'Explore Plus', exact: true }).click();
    await expect(page).toHaveURL(`${base}/plans`);
    await expect(page.getByText('KSh 200', { exact: false }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: 'Pay with M-Pesa', exact: true })).toBeDisabled();
    await page.screenshot({ path: 'tests/.runtime/plans-desktop.png', fullPage: true });
    pass('pricing renders correctly and unconfigured checkout cannot pretend to charge');
    await page.getByRole('link', { name: 'Back to setup' }).click();
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page).toHaveURL(`${base}/onboarding?step=4`);
    await page.getByText('I have my own AI API key', { exact: true }).click();
    await expect(page.getByLabel('Your API key', { exact: true })).toHaveAttribute(
        'type',
        'password',
    );
    await page.getByRole('button', { name: 'Skip for now' }).click();
    await expect(page).toHaveURL(`${base}/onboarding?step=5`);
    await page.getByRole('button', { name: 'Open my workspace' }).click();
    await expect(page).toHaveURL(`${base}/`);
    pass('AI and automation can be skipped to complete free onboarding');
    await page.goto(`${base}/settings?tab=ai`);
    await page.getByText('Add or replace my AI key', { exact: true }).click();
    await page
        .getByLabel('API key', { exact: true })
        .first()
        .fill('fixture-personal-key-never-live');
    await page.getByLabel('Model name', { exact: true }).first().fill('fixture-model');
    await page.getByLabel('Input price', { exact: true }).fill('1');
    await page.getByLabel('Output price', { exact: true }).fill('1');
    await page.getByRole('button', { name: 'Save my AI key', exact: true }).click();
    await expect(page.getByText('Saved connections: My Gemini.', { exact: true })).toBeVisible();
    expect(await page.content()).not.toContain('fixture-personal-key-never-live');
    pass('a free user can save a personal Gemini key without exposing it in HTML');
    await page.goto(`${base}/cv-review`);
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const paywall = await context.request.post(`${base}/api/cv/improve`, {
        headers: { 'X-CSRF-Token': token },
        data: {},
    });
    expect(paywall.status()).toBe(402);
    const automation = await context.request.post(`${base}/api/settings`, {
        headers: { 'X-CSRF-Token': token },
        data: { section: 'automation', mode: 'automatic', confirm_automatic: '1' },
    });
    expect(automation.status()).toBe(402);
    pass('paid CV improvements and automation are protected on the server');
    for (const route of [
        '/discover',
        '/settings?tab=discovery',
        '/settings?tab=ai',
        '/settings?tab=automation',
        '/plans',
        '/cv-review',
    ]) {
        expect((await page.goto(`${base}${route}`)).status()).toBe(200);
        await page.setViewportSize({ width: 390, height: 844 });
        expect(
            await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth),
            route,
        ).toBe(true);
        await page.screenshot({
            path: `tests/.runtime/saas-${route.replaceAll(/[^a-z]/g, '-')}-mobile.png`,
            fullPage: true,
        });
    }
    pass('free workspace, settings and plans render without horizontal overflow on mobile');
    await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await expect(page).toHaveURL(`${base}/login`);
    await page.waitForLoadState('load');
    await page.getByLabel('Email address', { exact: true }).fill(email);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page).toHaveURL(`${base}/`);
    pass('returning users sign in directly to their completed workspace');

    await page.setViewportSize({ width: 1440, height: 1000 });
    await page.goto(`${base}/settings?tab=gmail`);
    let authorization;
    const network = await context.newCDPSession(page);
    network.on('Fetch.requestPaused', async ({ requestId, request }) => {
        const url = new URL(request.url);
        if (url.pathname === '/o/oauth2/v2/auth') authorization = url;
        await network.send('Fetch.fulfillRequest', {
            requestId,
            responseCode: 200,
            responseHeaders: [{ name: 'Content-Type', value: 'text/html' }],
            body: Buffer.from('<h1>Gmail consent fixture</h1>').toString('base64'),
        });
    });
    await network.send('Fetch.enable', {
        patterns: [{ urlPattern: 'https://accounts.google.com/*', requestStage: 'Request' }],
    });
    await page.getByRole('button', { name: 'Connect Gmail', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Gmail consent fixture' })).toBeVisible();
    expect(authorization.searchParams.get('redirect_uri')).toBe(`${base}/Callback`);
    expect(authorization.searchParams.get('scope')).toContain('gmail.send');
    expect(authorization.searchParams.get('access_type')).toBe('offline');
    pass('Connect Gmail opens Google with the same registered capital-C callback');
    const cancelled = await page.goto(
        `${base}/Callback?error=access_denied&state=${authorization.searchParams.get('state')}`,
    );
    expect(cancelled.status()).toBe(400);
    await expect(
        page.getByText('The Gmail connection request expired or was rejected. Connect again.', {
            exact: true,
        }),
    ).toBeVisible();
    await page.goto(base);
    await expect(page).toHaveURL(`${base}/`);
    pass(
        'the shared callback routes Gmail cancellation correctly and preserves the signed-in account',
    );

    const promoContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
    const promoPage = await promoContext.newPage();
    promoPage.on('pageerror', (error) => errors.push(error.message));
    await promoPage.goto(`${base}/register`);
    await promoPage.getByLabel('Email address', { exact: true }).fill(promoEmail);
    await promoPage.getByRole('button', { name: 'Continue', exact: true }).click();
    await promoPage.getByLabel('Your name', { exact: true }).fill('Promo Friend');
    await promoPage.getByLabel('Password', { exact: true }).fill(password);
    await promoPage.getByLabel('Confirm password', { exact: true }).fill(password);
    await promoPage.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(promoPage).toHaveURL(`${base}/onboarding`);
    promoUserId = JSON.parse(
        execFileSync('php', ['tests/saas-browser-fixtures.php', 'lookup', promoEmail], {
            encoding: 'utf8',
        }),
    ).id;
    await promoPage.goto(`${base}/plans`);
    expect(await promoPage.content()).not.toContain('IAMPOOR001');
    const promoToken = await promoPage.locator('meta[name="csrf-token"]').getAttribute('content');
    const missingToken = await promoContext.request.post(`${base}/api/billing/promo`, {
        data: { code: 'IAMPOOR001' },
    });
    expect(missingToken.status()).toBe(403);
    const anonymous = await fetch(`${base}/api/billing/promo`, {
        method: 'POST',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ code: 'IAMPOOR001' }),
    });
    expect(anonymous.status).toBe(401);
    pass(
        'promo redemption requires a signed-in account and CSRF, and the code is not advertised in HTML',
    );
    await promoPage.getByLabel('Promo code', { exact: true }).fill('INVALID001');
    await promoPage.getByRole('button', { name: 'Apply promo code', exact: true }).click();
    await expect(promoPage.locator('#promo .form-error')).toContainText(
        'invalid or is no longer available',
    );
    await promoPage.getByLabel('Promo code', { exact: true }).fill('  iampoor001  ');
    await promoPage.getByRole('button', { name: 'Apply promo code', exact: true }).click();
    await expect(
        promoPage.getByText('Your lifetime Plus plan is active.', { exact: true }),
    ).toBeVisible();
    await expect(
        promoPage.getByRole('button', { name: 'Pay with M-Pesa', exact: true }),
    ).toHaveCount(0);
    await expect(promoPage.getByLabel('M-Pesa phone number', { exact: true })).toHaveCount(0);
    expect(await promoPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(
        true,
    );
    await promoPage.screenshot({
        path: 'tests/.runtime/promo-lifetime-mobile.png',
        fullPage: true,
    });
    pass(
        'an onboarding user redeems the lifetime code on mobile without a phone number or M-Pesa payment',
    );
    const repeated = await promoContext.request.post(`${base}/api/billing/promo`, {
        headers: { 'X-CSRF-Token': promoToken },
        data: { code: 'IAMPOOR001', user_id: userId },
    });
    expect(repeated.status()).toBe(200);
    await page.goto(`${base}/plans`);
    await expect(page.getByLabel('Promo code', { exact: true })).toBeVisible();
    await expect(page.getByText('Your lifetime Plus plan is active.', { exact: true })).toHaveCount(
        0,
    );
    pass('repeat redemption is safe and ignores attempts to select another account');
    for (let i = 0; i < 3; i++) {
        var limited = await promoContext.request.post(`${base}/api/billing/promo`, {
            headers: { 'X-CSRF-Token': promoToken },
            data: { code: 'INVALID001' },
        });
    }
    expect(limited.status()).toBe(429);
    pass('promo-code attempts are rate limited');
    await promoPage.getByRole('link', { name: 'Back to setup' }).click();
    await expect(promoPage).toHaveURL(`${base}/onboarding`);
    await promoPage.getByRole('button', { name: 'Finish setup later', exact: true }).click();
    await expect(promoPage).toHaveURL(`${base}/`);
    await promoPage.goto(`${base}/settings?tab=automation`);
    await expect(promoPage.getByLabel('Submission mode', { exact: true })).toBeVisible();
    await promoPage.getByLabel('Submission mode', { exact: true }).selectOption('automatic');
    await promoPage.getByLabel('I explicitly enable Automatic mode', { exact: false }).check();
    await promoPage.getByLabel('Maximum applications per day', { exact: true }).fill('1');
    await promoPage.getByRole('button', { name: 'Save automation settings', exact: true }).click();
    await expect(
        promoPage.getByLabel('Run scheduled job discovery.', { exact: true }),
    ).toBeChecked();
    await expect(
        promoPage.getByLabel('Automatically queue AI analysis for new opportunities.', {
            exact: true,
        }),
    ).toBeChecked();
    await expect(
        promoPage.getByLabel('Prepare applications for eligible matches', { exact: false }),
    ).toBeChecked();
    await expect(promoPage.getByLabel('Maximum applications per day', { exact: true })).toHaveValue(
        '1',
    );
    await expect(
        promoPage.getByRole('region', { name: 'Application automation status' }),
    ).toContainText('Import your uploaded CV');
    await expect(
        promoPage.getByRole('region', { name: 'Application automation status' }),
    ).toContainText('Connect Gmail');
    pass(
        'Automatic mode enables every pipeline stage and exposes missing setup instead of silently waiting',
    );
    pass('promo lifetime access survives leaving onboarding and unlocks automation settings');
    await promoContext.close();
    expect(errors).toEqual([]);
    pass('inline Alpine components run with no JavaScript errors');
    console.log(`${assertions} SaaS browser checks passed.`);
} finally {
    if (!userId) {
        try {
            userId = JSON.parse(
                execFileSync('php', ['tests/saas-browser-fixtures.php', 'lookup', email], {
                    encoding: 'utf8',
                }),
            )?.id;
        } catch {}
    }
    await browser?.close();
    server.kill();
    if (!promoUserId) {
        try {
            promoUserId = JSON.parse(
                execFileSync('php', ['tests/saas-browser-fixtures.php', 'lookup', promoEmail], {
                    encoding: 'utf8',
                }),
            )?.id;
        } catch {}
    }
    for (const id of [userId, promoUserId, fixture.id].filter(Boolean))
        execFileSync('php', ['tests/browser-fixtures.php', 'cleanup', String(id)], {
            stdio: 'ignore',
        });
}
