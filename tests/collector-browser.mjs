import { chromium, expect } from '@playwright/test';
import { execFileSync, spawn } from 'node:child_process';
import { mkdir } from 'node:fs/promises';

const fixture = JSON.parse(
    execFileSync('php', ['tests/collector-fixtures.php', 'setup'], { encoding: 'utf8' }),
);
const base = 'http://127.0.0.1:8861';
const token = 'f'.repeat(64);
const server = spawn('php', ['-S', '127.0.0.1:8861', '-t', 'Public', 'Public/router.php'], {
    env: {
        ...process.env,
        DB_DATABASE: process.env.JOBFLOW_TEST_DATABASE ?? 'jobflow_test',
        SESSION_SECURE: 'false',
        DOCUMENT_STORAGE: 'local',
        GEMINI_API_KEY: '',
        SMTP_PASSWORD: '',
        DOCUMENT_RENDERER_URL: '',
        DOCUMENT_RENDERER_TOKEN: '',
        COLLECTOR_ENABLED: 'true',
        COLLECTOR_PROJECT: '',
        COLLECTOR_TOKEN: token,
        APP_URL: base,
    },
    stdio: 'ignore',
});
let browser;
const failures = [];
async function login(context, account, destination = '/') {
    const page = await context.newPage();
    page.on('pageerror', (error) => failures.push(error.message));
    await page.goto(`${base}/login`);
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const response = await context.request.post(`${base}/login`, {
        headers: { 'X-CSRF-Token': csrf },
        data: { email: account.email, password: fixture.password },
    });
    expect(response.status()).toBe(200);
    const result = await response.json();
    expect(result.redirect).toBe(destination);
    await page.goto(`${base}${result.redirect}`);
    await expect(page).toHaveURL(`${base}${destination}`);
    return page;
}
try {
    for (let i = 0; i < 40; i++) {
        try {
            if ((await fetch(`${base}/login`)).ok) break;
        } catch {}
        await new Promise((resolve) => setTimeout(resolve, 200));
    }
    browser = await chromium.launch({
        executablePath: process.env.CHROMIUM_PATH || chromium.executablePath(),
        args: ['--no-sandbox'],
    });
    const admin = await browser.newContext({ viewport: { width: 1440, height: 1050 } });
    const user = await browser.newContext();
    const pending = await browser.newContext();
    const page = await login(admin, fixture.accounts.admin, '/admin');
    const ordinary = await login(user, fixture.accounts.user);
    const unfinished = await login(pending, fixture.accounts.new_user, '/onboarding');
    for (const path of ['/', '/onboarding', '/login', '/register']) {
        await page.goto(`${base}${path}`);
        await expect(page).toHaveURL(`${base}/admin`);
    }
    console.log(
        'PASS admins sign in directly to their portal and bypass setup on returning visits',
    );
    await unfinished.goto(`${base}/`);
    await expect(unfinished).toHaveURL(`${base}/onboarding`);
    console.log('PASS ordinary accounts retain their setup and completed-workspace destinations');

    for (const javaScriptEnabled of [true, false]) {
        const context = await browser.newContext({ javaScriptEnabled });
        const signIn = await context.newPage();
        signIn.on('pageerror', (error) => failures.push(error.message));
        await signIn.goto(`${base}/login`);
        await signIn
            .getByLabel('Email address', { exact: true })
            .fill(fixture.accounts.admin.email);
        await signIn.getByRole('button', { name: 'Continue', exact: true }).click();
        await expect(signIn.getByLabel('Password', { exact: true })).toBeVisible();
        await signIn.getByLabel('Password', { exact: true }).fill(fixture.password);
        await signIn.getByRole('button', { name: 'Continue', exact: true }).click();
        await expect(signIn).toHaveURL(`${base}/admin`);
        await expect(
            signIn.getByRole('heading', { name: 'Administration', exact: true }),
        ).toBeVisible();
        await context.close();
        console.log(
            `PASS admin email-first sign-in skips setup with JavaScript ${javaScriptEnabled ? 'enabled' : 'disabled'}`,
        );
    }

    execFileSync('php', ['tests/collector-fixtures.php', 'grant', fixture.tag]);
    await unfinished.goto(`${base}/`);
    await expect(unfinished).toHaveURL(`${base}/admin`);
    execFileSync('php', ['tests/collector-fixtures.php', 'revoke', fixture.tag]);
    await unfinished.goto(`${base}/admin`);
    await expect(unfinished).toHaveURL(`${base}/onboarding`);
    console.log('PASS role changes update portal access without completing job-seeker setup');

    await expect(page.getByRole('heading', { name: 'Administration', exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Collect now', exact: true })).toBeDisabled();
    console.log(
        'PASS administrators see the operations area; unconfigured cloud runs are disabled',
    );
    const unauthorized = await user.request.get(`${base}/admin`);
    expect(unauthorized.status()).toBe(403);
    const csrf = await ordinary.locator('meta[name="csrf-token"]').getAttribute('content');
    expect(
        (
            await user.request.post(`${base}/api/admin/collect`, {
                headers: { 'X-CSRF-Token': csrf },
                data: {},
            })
        ).status(),
    ).toBe(403);
    expect((await admin.request.post(`${base}/api/admin/jobs`, { data: {} })).status()).toBe(403);
    console.log('PASS admin pages and mutations enforce roles and CSRF');

    for (const label of ['My CV', 'My jobs', 'Applications', 'Documents', 'Plans & billing']) {
        await expect(page.getByRole('link', { name: label, exact: true })).toHaveCount(0);
    }
    for (const path of ['/profile', '/documents', '/jobs', '/plans', '/settings']) {
        await page.goto(`${base}${path}`);
        await expect(page).toHaveURL(`${base}/admin`);
    }
    expect((await admin.request.post(`${base}/api/jobs`, { data: {} })).status()).toBe(403);
    console.log('PASS admin navigation and direct routes contain only administration features');

    await page
        .getByRole('navigation', { name: 'Administration sections' })
        .getByRole('link', { name: 'Sources', exact: true })
        .click();
    await expect(
        page.getByRole('heading', { name: 'Collection sources', exact: true }),
    ).toBeVisible();
    await expect(page.getByText('MyJobMag Kenya', { exact: true })).toBeVisible();
    await expect(page.getByText('Indeed via Google Jobs', { exact: true })).toBeVisible();
    console.log('PASS the configured source list and source form render');

    await page.getByRole('link', { name: 'Add catalogue job', exact: true }).click();
    await page.getByLabel('Job title', { exact: true }).fill(`Admin added fixture ${fixture.tag}`);
    await page.getByLabel('Employer', { exact: true }).fill(`Collector browser ${fixture.tag}`);
    await page.getByLabel('Description', { exact: true }).fill('Test-only admin-created vacancy.');
    await page.getByRole('button', { name: 'Save catalogue job', exact: true }).click();
    await expect(page).toHaveURL(`${base}/admin`);
    await expect(
        page.getByText(`Admin added fixture ${fixture.tag}`, { exact: true }),
    ).toBeVisible();
    console.log('PASS an administrator publishes a manual vacancy through the real form');

    await ordinary.goto(`${base}/catalogue/${fixture.catalogue_id}`);
    await expect(
        ordinary.getByRole('heading', { name: `Catalogue fixture ${fixture.tag}` }),
    ).toBeVisible();
    expect(await ordinary.evaluate(() => window.fixtureXss)).toBeUndefined();
    await ordinary.getByRole('button', { name: 'Save to my jobs', exact: true }).click();
    await expect(ordinary).toHaveURL(/\/jobs\/[0-9]+$/);
    await expect(
        ordinary.getByText(`Catalogue fixture ${fixture.tag}`, { exact: true }).first(),
    ).toBeVisible();
    console.log('PASS shared jobs render safely and save to the user’s private workspace');

    expect(
        (
            await fetch(`${base}/api/collector/claim`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: '{}',
            })
        ).status,
    ).toBe(401);
    const claimResponse = await fetch(`${base}/api/collector/claim`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({ run_id: fixture.run_id, execution: `browser-${fixture.tag}` }),
    });
    expect(claimResponse.status).toBe(200);
    const claim = await claimResponse.json();
    const job = {
        title: `Imported fixture ${fixture.tag}`,
        employer: `Collector browser ${fixture.tag}`,
        description: 'Test-only callback import.',
        location: 'Kenya',
        original_url: `https://example.com/imported/${fixture.tag}`,
    };
    const batch = {
        run_id: fixture.run_id,
        run_token: claim.run_token,
        source_id: fixture.source_id,
        batch_key: 'browser-0',
        jobs: [job],
    };
    for (let attempt = 0; attempt < 2; attempt++) {
        const response = await fetch(`${base}/api/collector/batch`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
            body: JSON.stringify(batch),
        });
        expect(response.status).toBe(200);
        expect((await response.json()).replayed).toBe(attempt === 1);
    }
    const finish = await fetch(`${base}/api/collector/finish`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
        body: JSON.stringify({
            run_id: fixture.run_id,
            run_token: claim.run_token,
            results: [{ source_id: fixture.source_id, status: 'completed', error: '' }],
        }),
    });
    expect(finish.status).toBe(200);
    await page.goto(`${base}/admin?tab=runs`);
    await expect(page.getByText('1 new · 0 refreshed', { exact: true })).toBeVisible();
    console.log(
        'PASS authenticated collector callbacks import, deduplicate and report authoritative counts',
    );

    expect((await fetch(`${base}/api/collector/status`)).status).toBe(401);
    const health = await fetch(`${base}/api/collector/status`, {
        headers: { Authorization: `Bearer ${token}` },
    });
    const healthBody = await health.json();
    expect(healthBody.catalogue_enabled).toBe(true);
    expect(JSON.stringify(healthBody)).not.toContain(token);
    const userCsrf = await ordinary.locator('meta[name="csrf-token"]').getAttribute('content');
    const search = await user.request.post(`${base}/api/automation/search`, {
        headers: { 'X-CSRF-Token': userCsrf },
        data: {},
    });
    expect(search.status()).toBe(200);
    const searchBody = await search.json();
    expect(searchBody.found).toBeGreaterThan(0);
    expect(searchBody.task_id).toBeUndefined();
    await ordinary.goto(`${base}/discover`);
    await expect(
        ordinary.getByText(`Imported fixture ${fixture.tag}`, { exact: true }),
    ).toBeVisible();
    await ordinary.goto(`${base}/settings?tab=discovery`);
    await expect(ordinary.getByText('Advanced discovery settings', { exact: true })).toHaveCount(0);
    console.log(
        'PASS discovery saves newly collected jobs immediately without a queue worker or personal scraper setup',
    );

    await mkdir('tests/.runtime', { recursive: true });
    await page.screenshot({ path: 'tests/.runtime/collector-admin-desktop.png', fullPage: true });
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${base}/admin?tab=sources`);
    expect(
        await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1),
    ).toBe(true);
    await page.screenshot({ path: 'tests/.runtime/collector-admin-mobile.png', fullPage: true });
    expect(failures).toEqual([]);
    console.log('PASS admin works at mobile width without JavaScript errors');
} finally {
    await browser?.close();
    server.kill('SIGTERM');
    execFileSync('php', ['tests/collector-fixtures.php', 'cleanup', fixture.tag], {
        stdio: 'inherit',
    });
}
