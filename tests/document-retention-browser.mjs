import { chromium, expect as baseExpect } from '@playwright/test';
import { spawn, execFileSync } from 'node:child_process';
import { mkdir } from 'node:fs/promises';

const base = 'http://127.0.0.1:8858';
const expect = baseExpect.configure({ timeout: 60000 });
const env = {
    ...process.env,
    DB_DATABASE: process.env.JOBFLOW_TEST_DATABASE || 'jobflow_test',
    DOCUMENT_STORAGE: 'local',
    SESSION_SECURE: 'false',
    GEMINI_API_KEY: '',
    SMTP_PASSWORD: '',
    APP_URL: base,
};
const fixture = JSON.parse(
    execFileSync('php', ['tests/browser-fixtures.php', 'setup-retention'], {
        env,
        encoding: 'utf8',
    }),
);
const server = spawn('php', ['-S', '127.0.0.1:8858', '-t', 'Public', 'Public/router.php'], {
    env,
    stdio: 'ignore',
});
let browser;
let other;
const errors = [];
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
    const context = await browser.newContext();
    const page = await context.newPage();
    page.setDefaultNavigationTimeout(60000);
    const go = (path) => page.goto(`${base}${path}`, { waitUntil: 'domcontentloaded' });
    page.on('pageerror', (error) => errors.push(error.message));
    await go('/login');
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    expect(
        (
            await context.request.post(`${base}/login`, {
                headers: { 'X-CSRF-Token': csrf },
                data: { email: fixture.email, password: fixture.password },
            })
        ).status(),
    ).toBe(200);
    await go('/documents');
    const active = page.locator(`[data-document-id="${fixture.document_id}"]`);
    const cold = page.locator(`[data-document-id="${fixture.archived_id}"]`);
    await expect(cold).toHaveClass(/document-card-archived/);
    await expect(
        cold.getByRole('button', { name: 'Download unavailable in cold storage' }),
    ).toBeDisabled();
    await expect(cold.getByRole('button', { name: 'Favorite & restore' })).toBeEnabled();
    console.log('PASS cold documents are gray, labeled and cannot be downloaded');
    const saved = page.waitForResponse(
        (response) =>
            response.url().endsWith(`/api/documents/${fixture.document_id}/favorite`) &&
            response.request().method() === 'POST',
    );
    await active.getByRole('button', { name: 'Add to favorites' }).click();
    expect((await saved).status()).toBe(200);
    await expect(active.getByRole('button', { name: 'Remove favorite' })).toHaveAttribute(
        'aria-pressed',
        'true',
    );
    await page.getByRole('link', { name: 'Favorites', exact: true }).click();
    await expect(page.locator('.document-card')).toHaveCount(1);
    await expect(active).toBeVisible();
    console.log('PASS favorites persist and the Favorites filter shows only saved documents');
    await active.getByRole('link', { name: 'View details' }).click();
    await page.getByRole('button', { name: 'Remove favorite' }).click();
    await expect(page.getByRole('button', { name: 'Add to favorites' })).toHaveAttribute(
        'aria-pressed',
        'false',
    );
    await go('/documents?filter=favorites');
    await expect(page.getByText('No favorites yet', { exact: true })).toBeVisible();
    console.log('PASS favorites can be removed from the document details page');
    await go(`/documents/${fixture.archived_id}`);
    await expect(page.getByText('This document is in cold storage', { exact: true })).toBeVisible();
    await expect(page.locator('.pdf-reader')).toHaveCount(0);
    await expect(page.getByRole('button', { name: 'In cold storage' })).toBeDisabled();
    expect(
        (await context.request.get(`${base}/documents/${fixture.archived_id}/download`)).status(),
    ).toBe(409);
    const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    expect(
        (
            await context.request.post(`${base}/api/documents/${fixture.document_id}/favorite`, {
                data: { favorite: 1 },
            })
        ).status(),
    ).toBe(403);
    expect(
        (
            await context.request.post(`${base}/api/documents/${fixture.document_id}/favorite`, {
                headers: { 'X-CSRF-Token': token },
                data: { favorite: 'invalid' },
            })
        ).status(),
    ).toBe(422);
    console.log('PASS archived previews stay closed and favorite requests validate CSRF and input');
    other = JSON.parse(
        execFileSync('php', ['tests/browser-fixtures.php', 'setup'], { env, encoding: 'utf8' }),
    );
    expect(
        (
            await context.request.post(`${base}/api/documents/${other.document_id}/favorite`, {
                headers: { 'X-CSRF-Token': token },
                data: { favorite: 1 },
            })
        ).status(),
    ).toBe(404);
    for (const width of [1440, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await go('/documents');
        expect(
            await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),
        ).toBe(true);
        await mkdir('tests/.runtime', { recursive: true });
        await page.screenshot({
            path: `tests/.runtime/document-retention-${width}.png`,
            fullPage: true,
        });
    }
    expect(errors).toEqual([]);
    console.log('PASS account isolation and document library layout on desktop and mobile');
} finally {
    if (browser) await browser.close();
    server.kill();
    for (const account of [fixture, other].filter(Boolean)) {
        execFileSync('php', ['tests/browser-fixtures.php', 'cleanup', String(account.id)], { env });
    }
}
