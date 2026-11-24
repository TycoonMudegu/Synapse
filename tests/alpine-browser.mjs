import { chromium, expect } from '@playwright/test';
import { spawn, execFileSync } from 'node:child_process';

const base = 'http://127.0.0.1:8853';
const fixture = JSON.parse(
    execFileSync('php', ['tests/browser-fixtures.php', 'setup'], { encoding: 'utf8' }),
);
const server = spawn('php', ['-S', '127.0.0.1:8853', '-t', 'Public', 'Public/router.php'], {
    env: {
        ...process.env,
        DB_DATABASE: process.env.JOBFLOW_TEST_DATABASE ?? 'jobflow_test',
        APP_URL: base,
        DOCUMENT_STORAGE: process.env.JOBFLOW_TEST_STORAGE || 'local',
        SESSION_SECURE: 'false',
        GEMINI_API_KEY: '',
        SMTP_PASSWORD: '',
    },
    stdio: 'ignore',
});
let browser;
let assertions = 0;
const pass = (message) => {
    assertions++;
    console.log(`PASS ${message}`);
};
const fulfill = (route, body, status = 200) =>
    route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
try {
    for (let i = 0; i < 40; i++) {
        try {
            if ((await fetch(`${base}/login`)).status === 200) break;
        } catch {}
        await new Promise((resolve) => setTimeout(resolve, 200));
    }
    browser = await chromium.launch({
        executablePath: process.env.CHROMIUM_PATH || chromium.executablePath(),
        args: ['--no-sandbox'],
    });
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
    const errors = [];
    const scripts = new Set();
    page.on('pageerror', (error) => errors.push(error.message));
    page.on('request', (request) => {
        if (request.resourceType() === 'script') scripts.add(new URL(request.url()).pathname);
    });
    const initial = await page.goto(`${base}/login`);
    const csp = initial.headers()['content-security-policy'];
    expect(csp).toMatch(/script-src 'self' 'nonce-[A-Za-z0-9+/=]+'/);
    expect(csp).toContain("script-src-attr 'none'");
    expect(csp).not.toContain('unsafe-inline');
    expect(csp).not.toContain('unsafe-eval');
    const nonce = await page
        .locator('script[nonce]')
        .first()
        .evaluate((script) => script.nonce);
    expect(csp).toContain(`'nonce-${nonce}'`);
    const next = await page.reload();
    expect(next.headers()['content-security-policy']).not.toBe(csp);
    await page.getByLabel('Email address', { exact: true }).fill(fixture.email);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await page.getByLabel('Password', { exact: true }).fill(fixture.password);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page).toHaveURL(`${base}/`);
    pass('Alpine runs under per-response nonces without unsafe-inline or unsafe-eval');

    await page.goto(`${base}/profile`);
    const employment = page.locator('[data-record-list="employment"]');
    await expect(employment.locator('[data-career-record]')).toHaveCount(1);
    await page
        .getByRole('heading', { name: 'Employment history', exact: true })
        .locator('..')
        .getByRole('button', { name: 'Add entry' })
        .click();
    await expect(employment.locator('[data-career-record]')).toHaveCount(2);
    const ids = await employment
        .locator('input')
        .evaluateAll((inputs) => inputs.map((input) => input.id));
    expect(new Set(ids).size).toBe(ids.length);
    await employment
        .locator('[data-career-record]')
        .last()
        .getByRole('button', { name: 'Remove this entry' })
        .click();
    await expect(employment.locator('[data-career-record]')).toHaveCount(1);
    pass('career entries added from a PHP template have working Alpine handlers and unique fields');

    const injected = '<img src=x onerror="window.alpineInjected=true">';
    await page.route(`**/api/documents/${fixture.document_id}/profile`, (route) =>
        fulfill(route, {
            ok: true,
            data: {
                full_name: 'Imported Fixture',
                email: fixture.email,
                phone: '0712345678',
                location: 'Nairobi',
                summary: injected,
                skills: ['PHP', 'SQL'],
                employment: [
                    {
                        position: 'Engineer',
                        employer: 'Fixture Company',
                        start_date: '2020',
                        end_date: '2025',
                        achievements: injected,
                    },
                ],
                education: [
                    {
                        qualification: 'Diploma',
                        institution: 'Fixture College',
                        completion_year: '2019',
                    },
                ],
                uncertainties: ['Review the dates.'],
            },
        }),
    );
    page.on('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Import into profile', exact: true }).click();
    await expect(page.getByLabel('Full name', { exact: true })).toHaveValue('Imported Fixture');
    await expect(page.getByLabel('Professional summary', { exact: true })).toHaveValue(injected);
    await expect(page.getByLabel('Position', { exact: true })).toHaveValue('Engineer');
    await expect(
        page.getByLabel('I have reviewed this profile', { exact: false }),
    ).not.toBeChecked();
    expect(await page.evaluate(() => window.alpineInjected ?? false)).toBe(false);
    await employment.getByRole('button', { name: 'Remove this entry' }).click();
    await expect(employment.locator('[data-career-record]')).toHaveCount(0);
    await page.reload();
    await expect(page.getByLabel('Full name', { exact: true })).toHaveValue('Browser Fixture');
    pass('CV import remains an unsaved draft and treats model strings as text');

    await page.goto(`${base}/jobs/add`);
    await page.getByRole('tab', { name: 'Paste a link', exact: true }).click();
    await expect(page.locator('#import-url')).toBeVisible();
    await page.getByRole('tab', { name: 'Paste a link', exact: true }).press('ArrowRight');
    await expect(page.getByRole('tab', { name: 'Upload a poster', exact: true })).toBeFocused();
    await expect(page.locator('#import-poster')).toBeVisible();
    await page.getByRole('tab', { name: 'Upload a poster', exact: true }).press('End');
    await expect(page.locator('#import-text')).toBeVisible();
    pass('job import tabs support mouse and keyboard navigation');

    const extracted = {
        title: 'Alpine imported developer',
        employer: 'Fixture Employer',
        description: 'A fixture vacancy for inline Alpine conversion.',
        employment_type: 'contract',
        workplace: 'remote',
        application_method: 'external',
        original_url: 'https://example.com/vacancy',
        uncertainties: ['No deadline advertised.'],
    };
    await page.route('**/api/jobs/extract', (route) =>
        fulfill(route, { ok: true, data: extracted, message: 'Fixture extraction ready.' }),
    );
    await page.getByLabel('Advertisement text', { exact: true }).fill('A fixture advertisement.');
    await page.getByRole('button', { name: 'Structure advertisement', exact: true }).click();
    await expect(page.getByLabel('Job title *', { exact: true })).toHaveValue(extracted.title);
    await expect(page.getByLabel('Employment type', { exact: true })).toHaveValue('contract');
    await expect(page.getByLabel('Work arrangement', { exact: true })).toHaveValue('remote');
    await expect(page.locator('#extraction-uncertainties')).toContainText(
        'No deadline advertised.',
    );
    await expect(page.getByLabel('I have checked the details', { exact: false })).not.toBeChecked();
    pass('advert extraction populates fields and preserves the factual review step');

    await page.getByRole('button', { name: 'Save opportunity', exact: false }).click();
    await expect(page).toHaveURL(/\/jobs\/\d+$/);
    const jobId = page.url().split('/').pop();
    await page.route(`**/api/jobs/${jobId}/assist`, (route) =>
        fulfill(route, {
            ok: true,
            data: { summary: injected, uncertainties: ['Fixture review needed.'] },
            message: 'A draft is ready.',
        }),
    );
    await page.getByRole('button', { name: 'Prepare draft', exact: true }).click();
    await expect(page.getByRole('status').filter({ hasText: injected })).toBeVisible();
    expect(await page.evaluate(() => window.alpineInjected ?? false)).toBe(false);
    pass('AI assistance renders literal text and review notes through Alpine');

    await page.route(`**/api/jobs/${jobId}/analyse`, (route) =>
        fulfill(route, { ok: false, message: 'Fixture Plus requirement.' }, 402),
    );
    await page.getByRole('button', { name: 'Analyse fit', exact: true }).click();
    await expect(
        page.getByRole('dialog', { name: 'A little extra help with Plus.' }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Maybe later', exact: true }).click();
    await expect(page.locator('#upgrade-dialog')).not.toBeVisible();
    pass('server paywalls open and close the inline upgrade dialog');

    await page.goto(`${base}/jobs`);
    await page.getByRole('searchbox', { name: 'Search jobs', exact: true }).fill('Alpine imported');
    await page.getByRole('button', { name: 'Filter', exact: true }).click();
    await expect(
        page.getByRole('link', { name: 'Alpine imported developer', exact: false }),
    ).toBeVisible();
    await expect(page).toHaveURL(/q=Alpine\+imported/);
    pass('job filters replace results and update the URL without losing Alpine state');

    let taskReads = 0;
    await page.route('**/api/automation/search', (route) =>
        fulfill(route, { ok: true, task_id: 424242, message: 'Fixture queued.' }),
    );
    await page.route('**/api/tasks/424242', (route) =>
        fulfill(route, {
            ok: true,
            data:
                ++taskReads === 1
                    ? { status: 'running', type: 'discovery' }
                    : {
                          status: 'failed',
                          type: 'discovery',
                          last_error: 'Fixture task needs review.',
                      },
        }),
    );
    await page.goto(`${base}/discover`);
    await page.getByRole('button', { name: 'Search now', exact: true }).click();
    await expect(page.locator('#task-region')).toContainText('Checking your job sites');
    expect(await page.evaluate(() => sessionStorage.getItem('jobflow-task'))).toBe('424242');
    await page.reload();
    await expect(page.locator('#task-region')).toContainText('Fixture task needs review.');
    expect(await page.evaluate(() => sessionStorage.getItem('jobflow-task'))).toBe(null);
    pass('queued tasks resume after reload and show backend failure details');

    expect([...scripts].sort()).toEqual(['/Assets/alpine.js', '/Assets/lucide.js']);
    expect(errors).toEqual([]);
    pass('only vendor scripts load externally and all inline components run without errors');
    console.log(`${assertions} Alpine browser checks passed.`);
} finally {
    await browser?.close();
    server.kill('SIGTERM');
    execFileSync('php', ['tests/browser-fixtures.php', 'cleanup', String(fixture.id)], {
        stdio: 'ignore',
    });
}
