import { chromium, expect as baseExpect } from '@playwright/test';
import { execFileSync, spawn } from 'node:child_process';
import { mkdir } from 'node:fs/promises';

const base = 'http://127.0.0.1:8858';
const expect = baseExpect.configure({ timeout: 15000 });
const env = {
    ...process.env,
    DB_DATABASE: process.env.JOBFLOW_TEST_DATABASE || 'jobflow_test',
    APP_URL: base,
    DOCUMENT_STORAGE: 'local',
    SESSION_SECURE: 'false',
    GEMINI_API_KEY: '',
    SMTP_PASSWORD: '',
    DOCUMENT_RENDERER_URL: '',
    DOCUMENT_RENDERER_TOKEN: '',
};
const fixture = JSON.parse(
    execFileSync('php', ['tests/browser-fixtures.php', 'setup'], { env, encoding: 'utf8' }),
);
const server = spawn('php', ['-S', '127.0.0.1:8858', '-t', 'Public', 'Public/router.php'], {
    env,
    stdio: 'ignore',
});
const email = `browser-entry-${Date.now()}@example.test`;
const password = 'new-entry-browser-password-123';
const screens = [
    { width: 1440, height: 900 },
    { width: 1366, height: 768 },
    { width: 1280, height: 720 },
    { width: 1024, height: 600 },
    { width: 390, height: 844 },
    { width: 360, height: 640 },
    { width: 844, height: 390 },
];
let browser,
    newId,
    checks = 0;
const pass = (message) => {
    console.log(`PASS ${message}`);
    checks++;
};
async function screenFits(page, footer) {
    for (const size of screens) {
        await page.setViewportSize(size);
        await expect
            .poll(() =>
                page.evaluate(
                    () =>
                        document.documentElement.scrollHeight <= innerHeight + 1 &&
                        document.documentElement.scrollWidth <= innerWidth + 1,
                ),
            )
            .toBe(true);
        if (footer) {
            const box = await page.locator(footer).boundingBox();
            expect(box.y).toBeGreaterThanOrEqual(0);
            expect(box.y + box.height).toBeLessThanOrEqual(size.height + 1);
        }
    }
    await page.setViewportSize({ width: 1366, height: 768 });
}
try {
    await mkdir('tests/.runtime', { recursive: true });
    for (let attempt = 0; attempt < 40; attempt++) {
        try {
            if ((await fetch(`${base}/login`)).ok) break;
        } catch {}
        await new Promise((resolve) => setTimeout(resolve, 200));
    }
    browser = await chromium.launch({
        executablePath: process.env.CHROMIUM_PATH || chromium.executablePath(),
        args: ['--no-sandbox'],
    });
    const context = await browser.newContext();
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto(base);
    await expect(page).toHaveURL(`${base}/login`);
    await expect(page).toHaveTitle(/Synapse/);
    await expect(page.getByLabel('Password', { exact: true })).not.toBeVisible();
    await expect(page.getByRole('link', { name: /create.*account|sign in/i })).toHaveCount(0);
    const logo = page.locator('.google-logo');
    await expect(logo).toBeVisible();
    expect(await logo.evaluate((image) => image.complete && image.naturalWidth > 0)).toBe(true);
    await screenFits(page);
    await page.screenshot({ path: 'tests/.runtime/synapse-entry.png' });
    pass('one branded email-first entry screen fits desktop, mobile and short screens');

    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    expect(
        (await context.request.post(`${base}/auth/continue`, { data: { email } })).status(),
    ).toBe(403);
    expect(
        (
            await context.request.post(`${base}/auth/continue`, {
                headers: { 'X-CSRF-Token': csrf },
                data: { stage: 'complete', email, password },
            })
        ).status(),
    ).toBe(409);
    pass('entry steps require CSRF and a server-side email selection');

    await page.getByLabel('Email address', { exact: true }).fill(fixture.email.toUpperCase());
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Your name', { exact: true })).not.toBeVisible();
    await expect(page.getByLabel('Email address', { exact: true })).toHaveValue(fixture.email);
    await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute(
        'autocomplete',
        'current-password',
    );
    await page.getByLabel('Password', { exact: true }).fill('incorrect-password');
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page.getByRole('alert')).toContainText('incorrect');
    const before = (await context.cookies()).find(
        (cookie) => cookie.name === 'jobflow_session',
    ).value;
    await page.getByLabel('Password', { exact: true }).fill(fixture.password);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page).toHaveURL(`${base}/`);
    expect(
        (await context.cookies()).find((cookie) => cookie.name === 'jobflow_session').value,
    ).not.toBe(before);
    expect(await page.locator('body').innerText()).not.toContain('JobFlow');
    await page.locator('form[action$="/logout"] button').click();
    pass(
        'known emails request a password, reject wrong passwords and rotate the session on success',
    );

    await page.getByLabel('Email address', { exact: true }).fill(email);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page.getByLabel('Your name', { exact: true })).toBeVisible();
    await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute(
        'autocomplete',
        'new-password',
    );
    await screenFits(page);
    await page.getByLabel('Your name', { exact: true }).fill('Entry Browser');
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByLabel('Confirm password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page).toHaveURL(`${base}/onboarding`);
    newId = Number(
        execFileSync(
            'php',
            [
                '-r',
                'require "tests/bootstrap.php"; echo (new App\\Repositories\\UserRepository())->byEmail($argv[1])["id"];',
                email,
            ],
            { env, encoding: 'utf8' },
        ),
    );
    pass('unknown emails register in the same flow and start onboarding');

    for (let step = 1; step <= 5; step++) {
        await expect(page.locator('[aria-current="step"]')).toContainText(String(step));
        if (step === 4) await page.getByText('I have my own AI API key', { exact: true }).click();
        await screenFits(page, '.setup-card-footer');
        const button = page.locator('.setup-card-footer button');
        await expect(button).toBeVisible();
        await page.screenshot({ path: `tests/.runtime/synapse-onboarding-${step}.png` });
        if (step === 1) {
            await page.getByLabel('Your job field', { exact: true }).selectOption('technology');
            await page.getByLabel('Where would you like to work?', { exact: true }).fill('Kenya');
        }
        await button.click();
        await expect(page).toHaveURL(
            step === 5 ? `${base}/` : `${base}/onboarding?step=${step + 1}`,
        );
    }
    pass('all five onboarding steps stay within the viewport with fixed, reachable actions');

    const native = await browser.newContext({ javaScriptEnabled: false });
    const nativePage = await native.newPage();
    await nativePage.goto(`${base}/register`);
    await expect(nativePage).toHaveURL(`${base}/login`);
    await nativePage.getByLabel('Email address', { exact: true }).fill(email);
    await nativePage.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(nativePage.getByLabel('Password', { exact: true })).toBeVisible();
    await nativePage.getByLabel('Password', { exact: true }).fill(password);
    await nativePage.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(nativePage).toHaveURL(`${base}/`);
    await native.close();
    pass(
        'the same email-first flow works without JavaScript and old registration links lead to it',
    );

    await page.locator('form[action$="/logout"] button').click();
    await page.getByLabel('Email address', { exact: true }).fill(email);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
    await context.clearCookies();
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page.getByLabel('Password', { exact: true })).not.toBeVisible();
    await expect(page.getByRole('alert')).toContainText('Start with your email');
    await expect(page.getByLabel('Email address', { exact: true })).toHaveValue(email);
    expect(errors).toEqual([]);
    pass('an expired entry session safely returns to the email step without losing the address');
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page.getByLabel('Password', { exact: true })).toBeVisible();
    const selectedToken = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const switched = await context.request.post(`${base}/auth/continue`, {
        headers: { 'X-CSRF-Token': selectedToken },
        data: { stage: 'complete', email: 'browser-forged@example.test', password },
    });
    expect(switched.status()).toBe(409);
    let throttled;
    for (let attempt = 0; attempt < 31; attempt++) {
        throttled = await context.request.post(`${base}/auth/continue`, {
            headers: { 'X-CSRF-Token': selectedToken },
            data: { stage: 'email', email },
        });
        if (throttled.status() === 429) break;
    }
    expect(throttled.status()).toBe(429);
    pass('email selection is session-bound and account lookups are rate limited');
    console.log(`${checks} entry and onboarding browser checks passed.`);
} catch (error) {
    for (const context of browser?.contexts() || []) {
        for (const page of context.pages())
            await page.screenshot({ path: 'tests/.runtime/entry-failure.png' }).catch(() => {});
    }
    throw error;
} finally {
    await browser?.close();
    server.kill();
    for (const id of [fixture.id, newId].filter(Boolean))
        execFileSync('php', ['tests/browser-fixtures.php', 'cleanup', String(id)], { env });
}
