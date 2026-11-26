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
        COLLECTOR_ENABLED: 'false',
        COLLECTOR_PROJECT: '',
        COLLECTOR_TOKEN: token,
        APP_URL: base,
    },
    stdio: 'ignore',
});
let browser;
const failures = [];
async function login(context, account) {
    const page = await context.newPage();
    page.on('pageerror', (error) => failures.push(error.message));
    await page.goto(`${base}/login`);
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const response = await context.request.post(`${base}/login`, {
        headers: { 'X-CSRF-Token': csrf },
        data: { email: account.email, password: fixture.password },
    });
    expect(response.status()).toBe(200);
    await page.goto(`${base}/`);
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
    const page = await login(admin, fixture.accounts.admin);
    const ordinary = await login(user, fixture.accounts.user);
    await page.goto(`${base}/admin`);
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

    await page.getByRole('link', { name: 'Sources', exact: true }).click();
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
