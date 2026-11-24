import { chromium, expect } from '@playwright/test';
import { spawn, execFileSync } from 'node:child_process';
import { mkdir, readFile } from 'node:fs/promises';

const externalBase = process.env.JOBFLOW_BROWSER_BASE_URL;
const base = externalBase?.replace(/\/$/, '') ?? 'http://127.0.0.1:8848';
const fixture = JSON.parse(
    execFileSync('php', ['tests/browser-fixtures.php', 'setup'], { encoding: 'utf8' }),
);
const server = externalBase
    ? null
    : spawn('php', ['-S', '127.0.0.1:8848', '-t', 'Public', 'Public/router.php'], {
          env: {
              ...process.env,
              DB_DATABASE: process.env.JOBFLOW_TEST_DATABASE ?? 'jobflow_test',
              DOCUMENT_STORAGE: process.env.JOBFLOW_TEST_STORAGE || 'local',
              SESSION_SECURE: 'false',
              GEMINI_API_KEY: '',
              SMTP_PASSWORD: '',
              DOCUMENT_RENDERER_URL: '',
              DOCUMENT_RENDERER_TOKEN: '',
              APP_URL: base,
          },
          stdio: 'ignore',
      });
let browser;
let assertions = 0;
function pass(message) {
    assertions += 1;
    console.log(`PASS ${message}`);
}
try {
    await mkdir('tests/.runtime', { recursive: true });
    for (let attempt = 0; attempt < 40; attempt++) {
        try {
            const response = await fetch(`${base}/login`);
            if (response.status === 200) break;
        } catch {}
        await new Promise((resolve) => setTimeout(resolve, 250));
    }
    browser = await chromium.launch({
        executablePath: process.env.CHROMIUM_PATH || chromium.executablePath(),
        args: ['--no-sandbox'],
    });
    const context = await browser.newContext({ viewport: { width: 1440, height: 1050 } });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(base);
    await expect(page).toHaveURL(`${base}/login`);
    await page.goto(`${base}/login`);
    pass('private pages redirect to sign-in');
    await expect(page.getByRole('heading', { name: 'Your next move starts here.' })).toBeVisible();
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const noCsrf = await context.request.post(`${base}/login`, {
        data: { email: fixture.email, password: fixture.password },
    });
    expect(noCsrf.status()).toBe(403);
    pass('sign-in rejects missing CSRF');
    const incorrect = await context.request.post(`${base}/login`, {
        headers: { 'X-CSRF-Token': csrf },
        data: { email: fixture.email, password: 'incorrect-fixture-password' },
    });
    expect(incorrect.status()).toBe(422);
    pass('invalid credentials are rejected');

    await page.getByLabel('Email address', { exact: true }).fill(fixture.email);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await page.getByLabel('Password', { exact: true }).fill('incorrect-fixture-password');
    let blockedPosts = 0;
    const countLoginPosts = (request) => {
        if (request.url() === `${base}/auth/continue` && request.method() === 'POST')
            blockedPosts += 1;
    };
    page.on('request', countLoginPosts);
    await page.route('**/api/auth/csrf', (route) =>
        route.fulfill({
            status: 503,
            contentType: 'application/json',
            body: JSON.stringify({ ok: false, message: 'Session service is unavailable.' }),
        }),
    );
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page.getByRole('alert')).toHaveText('Session service is unavailable.');
    expect(blockedPosts).toBe(0);
    await expect(page.getByLabel('Email address', { exact: true })).toHaveValue(fixture.email);
    await expect(page.getByLabel('Password', { exact: true })).toHaveValue(
        'incorrect-fixture-password',
    );
    page.off('request', countLoginPosts);
    await page.unroute('**/api/auth/csrf');
    pass('a failed session refresh preserves credentials and prevents sign-in submission');

    await context.clearCookies();
    const refresh = page.waitForResponse((response) => response.url() === `${base}/api/auth/csrf`);
    const recoveredLogin = page.waitForResponse(
        (response) =>
            response.url() === `${base}/auth/continue` && response.request().method() === 'POST',
    );
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    const refreshed = await refresh;
    expect(refreshed.status()).toBe(200);
    expect(refreshed.headers()['cache-control']).toContain('no-store');
    expect((await recoveredLogin).status()).toBe(409);
    await expect(page.getByRole('alert')).toHaveText('Start with your email again to continue.');
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
    expect(await page.locator('meta[name="csrf-token"]').getAttribute('content')).not.toBe(csrf);
    pass('an older sign-in form recovers after losing its session cookie without reloading');

    const beforeSession = (await context.cookies()).find(
        (cookie) => cookie.name === 'jobflow_session',
    ).value;
    await page.getByLabel('Password', { exact: true }).fill(fixture.password);
    await page.getByRole('button', { name: 'Show password' }).click();
    await expect(page.locator('#password')).toHaveAttribute('type', 'text');
    await page.getByRole('button', { name: 'Hide password' }).click();
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page).toHaveURL(`${base}/`, { timeout: 15000 });
    const afterSession = (await context.cookies()).find(
        (cookie) => cookie.name === 'jobflow_session',
    ).value;
    expect(beforeSession).not.toBe(afterSession);
    pass('sign-in regenerates the session and password visibility works');
    await expect(
        page.getByRole('heading', { name: /Good (morning|afternoon|evening), Browser/ }),
    ).toBeVisible();
    expect(await page.locator('.stat-number').allTextContents()).toEqual(['0', '0', '0', '0']);
    pass('empty dashboard displays real zero metrics');
    await page.screenshot({ path: 'tests/.runtime/dashboard-desktop.png', fullPage: true });
    for (const route of [
        '/discover',
        '/jobs',
        '/jobs/add',
        '/profile',
        '/applications',
        '/documents',
        '/inbox',
        '/activity',
        '/settings',
        '/settings?tab=discovery',
        '/settings?tab=ai',
        '/settings?tab=gmail',
        '/settings?tab=automation',
        '/settings?tab=documents',
        '/settings?tab=security',
        '/settings?tab=system',
        `/documents/${fixture.document_id}`,
    ]) {
        const response = await page.goto(`${base}${route}`);
        expect(response.status(), route).toBe(200);
        await expect(page.locator('h1')).toBeVisible();
    }
    pass('all application and settings pages render successfully');
    await page.goto(`${base}/settings?tab=discovery`);
    await page.getByText('Advanced discovery settings', { exact: true }).click();
    await expect(page.getByRole('heading', { name: 'MyJobMag Kenya', exact: true })).toBeVisible();
    await expect(
        page.getByRole('heading', { name: 'BrighterMonday Kenya', exact: true }),
    ).toBeVisible();
    const fuzuCard = page
        .locator('article')
        .filter({ has: page.getByRole('heading', { name: 'Fuzu', exact: true }) });
    await fuzuCard.getByRole('button', { name: 'Add source', exact: true }).click();
    await expect(fuzuCard.getByText('Untested', { exact: true })).toBeVisible();
    await expect(
        fuzuCard.getByRole('button', { name: 'Check public pages', exact: true }),
    ).toBeVisible();
    await expect(fuzuCard.getByRole('link', { name: 'Connect Gmail', exact: true })).toHaveCount(0);
    await fuzuCard.getByRole('button', { name: 'Use email alerts', exact: true }).click();
    await expect(fuzuCard.getByText('Needs connection', { exact: true })).toBeVisible();
    await expect(fuzuCard.getByRole('link', { name: 'Connect Gmail', exact: true })).toBeVisible();
    await page.goto(`${base}/settings?tab=gmail`);
    await expect(page.getByText('Job alerts from Fuzu', { exact: true })).toBeVisible();
    await page.goto(`${base}/settings?tab=discovery`);
    await fuzuCard.getByRole('button', { name: 'Use public pages', exact: true }).click();
    await expect(fuzuCard.getByText('Untested', { exact: true })).toBeVisible();
    await expect(
        fuzuCard.getByRole('button', { name: 'Check public pages', exact: true }),
    ).toBeVisible();
    pass('public sources save without Gmail and optional email mode switches persist');

    const corporateCard = page.locator('article').filter({
        has: page.getByRole('heading', { name: 'Corporate Staffing Kenya', exact: true }),
    });
    await corporateCard.getByRole('button', { name: 'Add source', exact: true }).click();
    await expect(corporateCard.getByText('Untested', { exact: true })).toBeVisible();
    await expect(
        corporateCard.getByRole('button', { name: 'Check public pages', exact: true }),
    ).toBeVisible();
    await expect(
        corporateCard.getByRole('button', { name: 'Use email alerts', exact: true }),
    ).toHaveCount(0);
    await expect(
        corporateCard.getByRole('link', { name: 'Browse jobs', exact: true }),
    ).toHaveAttribute('href', 'https://www.corporatestaffing.co.ke/category/it-jobs-in-kenya/');
    pass('Corporate Staffing adds as public pages without Gmail or browser configuration');

    const talentCard = page
        .locator('article')
        .filter({ has: page.getByRole('heading', { name: 'Talent.com Kenya', exact: true }) });
    await talentCard.getByRole('button', { name: 'Add source', exact: true }).click();
    await expect(talentCard.getByText('Untested', { exact: true })).toBeVisible();
    await expect(
        talentCard.getByRole('button', { name: 'Check public pages', exact: true }),
    ).toBeVisible();
    await expect(
        talentCard.getByRole('button', { name: 'Use email alerts', exact: true }),
    ).toHaveCount(0);
    await expect(
        talentCard.getByRole('link', { name: 'Browse jobs', exact: true }),
    ).toHaveAttribute('href', 'https://ke.talent.com/jobs/k-software-developer-l-kenya');
    pass('Talent.com adds as a public Kenya source without Gmail or an API key');

    const indeedCard = page.locator('article').filter({
        has: page.getByRole('heading', { name: 'Indeed via Google Jobs', exact: true }),
    });
    await indeedCard.getByRole('button', { name: 'Add source', exact: true }).click();
    await expect(indeedCard.getByRole('button', { name: 'Check API', exact: true })).toBeVisible();
    await expect(indeedCard.getByText(/Coverage depends on Google’s index/)).toBeVisible();
    await indeedCard.getByRole('button', { name: 'Check API', exact: true }).click();
    await expect(
        page.getByRole('alert').filter({ hasText: /Configure your SerpApi key/ }),
    ).toBeVisible();
    await page.reload();
    await expect(indeedCard.getByText(/Configure your SerpApi key/)).toBeVisible();
    pass('Indeed reports its Google Jobs coverage and requires the shared API connection');

    const googleCard = page
        .locator('article')
        .filter({ has: page.getByRole('heading', { name: 'Google Jobs', exact: true }) });
    await googleCard.getByRole('button', { name: 'Add source', exact: true }).click();
    await expect(googleCard.getByRole('button', { name: 'Check API', exact: true })).toBeVisible();
    await googleCard.getByRole('button', { name: 'Check API', exact: true }).click();
    await expect(
        page.getByRole('alert').filter({ hasText: /Configure your SerpApi key/ }),
    ).toBeVisible();
    await page.reload();
    await expect(googleCard.getByText(/Configure your SerpApi key/)).toBeVisible();
    const googleSettings = page.locator('#google-jobs');
    await googleSettings
        .getByLabel('SerpApi key', { exact: true })
        .fill('browser-fixture-private-key');
    await googleSettings
        .getByLabel('Google Jobs search query', { exact: true })
        .fill('PHP developer jobs');
    await googleSettings
        .getByLabel('Google Jobs search location', { exact: true })
        .fill('Nairobi, Kenya');
    await googleSettings
        .getByRole('button', { name: 'Save Google Jobs settings', exact: true })
        .click();
    await expect(googleSettings.getByText(/An encrypted API key is saved/)).toBeVisible();
    await expect(googleSettings.getByLabel('SerpApi key', { exact: true })).toHaveValue('');
    expect(await page.content()).not.toContain('browser-fixture-private-key');
    await expect(
        googleSettings.getByLabel('Google Jobs search query', { exact: true }),
    ).toHaveValue('PHP developer jobs');
    await googleSettings
        .getByLabel('Remove the saved SerpApi key and disable Google Jobs and Indeed collection')
        .check();
    await googleSettings
        .getByRole('button', { name: 'Save Google Jobs settings', exact: true })
        .click();
    await expect(googleSettings.getByText(/No API key is configured/)).toBeVisible();
    await expect(googleCard.getByText('Untested', { exact: true })).toBeVisible();
    await expect(indeedCard.getByText('Untested', { exact: true })).toBeVisible();
    pass(
        'Google Jobs reports missing configuration, saves private settings and removes its key without external requests',
    );

    await page.goto(`${base}/profile`);
    await page
        .getByLabel('Professional summary', { exact: true })
        .fill('Test fixture profile. Maintains PHP applications and SQL databases.');
    await page.getByLabel('Skills', { exact: true }).fill('PHP, SQL');
    await page.getByLabel(/I have reviewed this profile/).check();
    await page.getByRole('button', { name: 'Save career profile' }).click();
    await expect(
        page.getByRole('status').filter({ hasText: 'Career profile saved' }),
    ).toBeVisible();
    await page.reload();
    await expect(page.getByLabel('Professional summary', { exact: true })).toHaveValue(
        /Test fixture profile/,
    );
    pass('career profile saves asynchronously and persists');
    await page.goto(`${base}/jobs/add`);
    await page.getByLabel('Job title *', { exact: true }).fill('Browser fixture vacancy');
    await page.getByLabel('Employer *', { exact: true }).fill('Browser fixture organisation');
    await page
        .getByLabel('Job description *', { exact: true })
        .fill('Automated browser test fixture. Not a real vacancy.');
    await page.getByLabel('Location', { exact: true }).fill('Nairobi, Kenya');
    await page.getByLabel(/I have checked the details/).check();
    await page.getByRole('button', { name: 'Save opportunity' }).click();
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    await expect(
        page.getByRole('heading', { name: 'Browser fixture vacancy', exact: true }),
    ).toBeVisible();
    const jobUrl = page.url();
    await page.getByRole('link', { name: 'Edit details', exact: true }).click();
    await expect(page.getByLabel('Job title *', { exact: true })).toHaveValue(
        'Browser fixture vacancy',
    );
    await page
        .getByLabel('Requirements', { exact: true })
        .fill('Fixture requirement to verify editing.');
    await page.getByRole('button', { name: 'Save opportunity' }).click();
    await expect(page).toHaveURL(jobUrl);
    await page.getByRole('link', { name: 'Edit details', exact: true }).click();
    await expect(page.getByLabel('Requirements', { exact: true })).toHaveValue(
        'Fixture requirement to verify editing.',
    );
    await page.goto(jobUrl);
    pass('job editing preserves verified fields and requirement text');
    pass('manual job entry saves to the database and opens the vacancy');
    await page.goto(`${base}/settings?tab=discovery`);
    await page.getByLabel('Role focus', { exact: true }).selectOption('technology');
    await page.getByRole('button', { name: 'Save discovery settings', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: /saved/i })).toBeVisible();
    await page.reload();
    await expect(page.getByLabel('Role focus', { exact: true })).toHaveValue('technology');
    await page.goto(`${base}/discover`);
    await expect(page.getByText('Technology & IT focus', { exact: true })).toBeVisible();
    await expect(page.getByRole('link', { name: /Browser fixture vacancy/ })).toHaveCount(0);
    await page.goto(`${base}/jobs`);
    await expect(page.getByRole('link', { name: /Browser fixture vacancy/ })).toBeVisible();
    pass('technical role focus persists and filters Discover while retaining saved jobs');
    await page.goto(jobUrl);
    await page.getByRole('button', { name: 'Prepare application', exact: true }).click();
    await expect(page).toHaveURL(/\/applications\/\d+$/);
    const applicationUrl = page.url();
    await page
        .getByLabel('Your letter', { exact: true })
        .fill(
            'Dear Hiring Team,\n\nThis is a browser test fixture letter.\n\nYours faithfully,\nBrowser Fixture',
        );
    await page.getByRole('button', { name: 'Save letter version' }).click();
    await expect(page.getByLabel('Cover letter attachment', { exact: true })).not.toHaveValue('');
    await page
        .getByLabel('CV attachment', { exact: true })
        .selectOption(String(fixture.document_id));
    await page
        .getByLabel('Application email', { exact: true })
        .fill('Test fixture application email.');
    await page.getByLabel(/I have opened both attachments/).check();
    await page.getByRole('button', { name: 'Save & verify package' }).click();
    await expect(page.getByRole('button', { name: 'Update status' })).toBeVisible();
    await page.getByLabel('Move application to', { exact: true }).selectOption('approved');
    await page.getByRole('button', { name: 'Update status' }).click();
    await expect(page.locator('.page-heading .badge')).toHaveText('Approved');
    pass('manual cover letter export, attachment review and explicit approval work end to end');
    await page.goto(jobUrl);
    await page.getByRole('button', { name: 'Prepare application', exact: true }).click();
    await expect(page).toHaveURL(applicationUrl);
    pass('repeated application preparation returns the existing application');
    await page
        .getByLabel('Move application to', { exact: true })
        .selectOption('awaiting_external_action');
    await page.getByRole('button', { name: 'Update status' }).click();
    await expect(page.locator('.page-heading .badge')).toHaveText('Awaiting external action');
    await page.getByLabel('Move application to', { exact: true }).selectOption('submitted');
    await page
        .getByLabel('External submission confirmation', { exact: true })
        .fill('Browser fixture external confirmation');
    await page.getByRole('button', { name: 'Update status' }).click();
    await expect(page.locator('.page-heading .badge')).toHaveText('Submitted');
    await page
        .getByLabel('Interview date & time (Africa/Nairobi)', { exact: true })
        .fill('2026-12-15T10:30');
    await page.getByRole('button', { name: 'Save interview' }).click();
    await expect(page.locator('.page-heading .badge')).toHaveText('Interview');
    await expect(
        page.getByLabel('Interview date & time (Africa/Nairobi)', { exact: true }),
    ).toHaveValue('2026-12-15T10:30');
    pass('external confirmation and Nairobi-time interview scheduling persist');
    await page.goto(`${base}/documents`);
    await page.getByRole('button', { name: 'Upload document', exact: true }).click();
    await page.locator('#upload-dialog input[type=file]').setInputFiles({
        name: 'malicious.php',
        mimeType: 'application/x-php',
        buffer: Buffer.from('<?php echo "not safe";'),
    });
    await page
        .locator('#upload-dialog')
        .getByRole('button', { name: 'Upload document', exact: true })
        .click();
    await expect(page.locator('#upload-dialog .form-error')).toContainText('supported');
    pass('upload rejects executable content despite its filename');
    await page.locator('#upload-dialog input[type=file]').setInputFiles({
        name: 'fixture-upload.docx',
        mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        buffer: await readFile(fixture.path),
    });
    await page
        .locator('#upload-dialog')
        .getByRole('button', { name: 'Upload document', exact: true })
        .click();
    await expect(page).toHaveURL(/\/documents\/\d+$/);
    await expect(page.getByRole('heading', { name: 'fixture-upload.docx' })).toBeVisible();
    const downloadResponse = await context.request.get(`${page.url()}/download`);
    expect(downloadResponse.status()).toBe(200);
    expect(downloadResponse.headers()['content-type']).toContain('wordprocessingml');
    expect((await downloadResponse.body()).subarray(0, 2).toString()).toBe('PK');
    pass('real DOCX upload, extraction and authenticated download work');
    const uploadedDocumentUrl = page.url();
    await page.getByRole('button', { name: 'Create PDF preview', exact: true }).click();
    await expect(page.locator('#toasts [role="alert"]')).toContainText(
        'finish document rendering setup',
    );
    await expect(page).toHaveURL(uploadedDocumentUrl);
    const unchangedDownload = await context.request.get(`${uploadedDocumentUrl}/download`);
    expect(await unchangedDownload.body()).toEqual(await downloadResponse.body());
    pass('DOCX preview explains missing renderer setup and keeps the original document intact');
    await page.goto(`${base}/settings?tab=documents`);
    await expect(page.getByLabel('Document design', { exact: true })).toHaveValue('preserve');
    await page.getByLabel('Document design', { exact: true }).selectOption('simple');
    await Promise.all([
        page.waitForResponse(
            (response) =>
                response.url() === `${base}/api/settings` && response.request().method() === 'POST',
        ),
        page.getByRole('button', { name: 'Save document preferences', exact: true }).click(),
    ]);
    await expect(page.getByLabel('Document design', { exact: true })).toHaveValue('simple');
    await page.reload();
    await expect(page.getByLabel('Document design', { exact: true })).toHaveValue('simple');
    await page.getByLabel('Document design', { exact: true }).selectOption('preserve');
    await Promise.all([
        page.waitForResponse(
            (response) =>
                response.url() === `${base}/api/settings` && response.request().method() === 'POST',
        ),
        page.getByRole('button', { name: 'Save document preferences', exact: true }).click(),
    ]);
    await page.reload();
    await expect(page.getByLabel('Document design', { exact: true })).toHaveValue('preserve');
    pass(
        'uploaded design is the default and changing document layouts is an explicit saved choice',
    );
    await page.goto(`${base}/settings?tab=automation`);
    await page.getByLabel('Submission mode', { exact: true }).selectOption('automatic');
    await page.getByRole('button', { name: 'Save automation settings' }).click();
    await expect(page.locator('main form .form-error').first()).toContainText('Explicitly confirm');
    pass('Automatic mode requires explicit consent');
    await page.getByLabel('Submission mode', { exact: true }).selectOption('manual');
    await Promise.all([
        page.waitForEvent('load'),
        page.getByRole('button', { name: 'Save automation settings' }).click(),
    ]);
    await expect(page.getByLabel('Submission mode', { exact: true })).toHaveValue('manual');
    const protectedResponse = await context.request.get(`${base}/documents/999999999/download`);
    expect(protectedResponse.status()).toBe(404);
    expect((await context.request.get(`${base}/register`)).status()).toBe(200);
    expect([403, 404]).toContain((await context.request.get(`${base}/.env`)).status());
    pass('unknown documents and private files stay protected while registration is public');
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(base);
    await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    await expect(page.locator('#sidebar')).toHaveClass(/open/);
    await page
        .getByRole('button', { name: 'Close navigation', exact: true })
        .click({ position: { x: 350, y: 300 } });
    await expect(page.locator('#sidebar')).not.toHaveClass(/open/);
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
    ).toBe(true);
    await page.screenshot({ path: 'tests/.runtime/dashboard-mobile.png', fullPage: true });
    pass('mobile navigation and dashboard fit a 390px screen');
    for (const route of [
        '/jobs/add',
        '/profile',
        '/applications',
        '/settings?tab=ai',
        '/discover',
        '/settings?tab=discovery',
        '/settings?tab=gmail',
    ]) {
        await page.goto(`${base}${route}`);
        const fits = await page.evaluate(
            () => document.documentElement.scrollWidth <= window.innerWidth,
        );
        if (!fits) {
            console.log(
                await page.evaluate(() =>
                    [...document.querySelectorAll('body *')]
                        .filter(
                            (element) =>
                                element.getBoundingClientRect().right > window.innerWidth + 1,
                        )
                        .slice(0, 12)
                        .map((element) => ({
                            tag: element.tagName,
                            class: element.className,
                            width: Math.round(element.getBoundingClientRect().width),
                            text: element.textContent.trim().slice(0, 70),
                        })),
                ),
            );
            await page.screenshot({ path: 'tests/.runtime/mobile-overflow.png', fullPage: true });
        }
        expect(fits, route).toBe(true);
    }
    pass('core forms remain within the mobile viewport');
    await page.getByRole('button', { name: 'Open navigation', exact: true }).click();
    await page.getByRole('button', { name: 'Sign out', exact: true }).click();
    await expect(page).toHaveURL(`${base}/login`);
    await page.goto(base);
    await expect(page).toHaveURL(`${base}/login`);
    pass('sign-out invalidates the authenticated session');
    expect(errors).toEqual([]);
    pass('no uncaught JavaScript errors');
    console.log(`\n${assertions} browser checks passed.`);
} finally {
    if (browser) await browser.close();
    server?.kill('SIGTERM');
    execFileSync('php', ['tests/browser-fixtures.php', 'cleanup', String(fixture.id)]);
}
