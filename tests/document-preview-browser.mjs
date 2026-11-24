import { chromium, expect } from '@playwright/test';
import { spawn, execFileSync } from 'node:child_process';
import { createServer } from 'node:http';
import { mkdir } from 'node:fs/promises';

const base = 'http://127.0.0.1:8856';
const env = {
    ...process.env,
    DB_DATABASE: process.env.JOBFLOW_TEST_DATABASE ?? 'jobflow_test',
    DOCUMENT_STORAGE: process.env.JOBFLOW_TEST_STORAGE || 'local',
    SESSION_SECURE: 'false',
    GEMINI_API_KEY: '',
    SMTP_PASSWORD: '',
    DOCUMENT_RENDERER_URL: '',
    DOCUMENT_RENDERER_TOKEN: '',
    APP_URL: base,
};
const fixture = JSON.parse(
    execFileSync('php', ['tests/browser-fixtures.php', 'setup-preview'], { env, encoding: 'utf8' }),
);
const server = spawn('php', ['-S', '127.0.0.1:8856', '-t', 'Public', 'Public/router.php'], {
    env,
    stdio: 'ignore',
});
let browser;
let other;
let external;
let checks = 0;
const errors = [];
const pageErrors = [];
const pass = (message) => {
    checks++;
    console.log(`PASS ${message}`);
};
async function login(context, account) {
    const page = await context.newPage();
    await page.goto(`${base}/login`);
    const csrf = await page.locator('meta[name="csrf-token"]').getAttribute('content');
    const response = await context.request.post(`${base}/login`, {
        headers: { 'X-CSRF-Token': csrf },
        data: { email: account.email, password: account.password },
    });
    expect(response.status()).toBe(200);
    return page;
}
async function expectRenderedPdf(page) {
    const reader = page.locator('.pdf-reader');
    const canvas = reader.locator('.pdf-reader-canvas').first();
    await expect(canvas).toBeVisible({ timeout: 30000 });
    await expect
        .poll(
            () =>
                canvas.evaluate((element) => {
                    if (element.width < 100 || element.height < 100) return false;
                    const pixels = element
                        .getContext('2d')
                        .getImageData(0, 0, element.width, element.height).data;
                    // A blank page or an unpainted canvas does not count as a rendered document.
                    for (let i = 0; i < pixels.length; i += 16) {
                        if (pixels[i + 3] > 0 && pixels[i] + pixels[i + 1] + pixels[i + 2] < 600)
                            return true;
                    }
                    return false;
                }),
            { timeout: 30000 },
        )
        .toBe(true)
        .catch(async (error) => {
            await mkdir('tests/.runtime', { recursive: true });
            await page.screenshot({ path: 'tests/.runtime/document-preview-failure.png' });
            console.log('PDF reader console errors:', errors);
            console.log('PDF reader page errors:', pageErrors);
            console.log(
                'PDF reader state:',
                await reader.evaluate((element) => ({
                    status: element.querySelector('.pdf-reader-status')?.textContent.trim(),
                    error: element.querySelector('[role="alert"]')?.textContent.trim(),
                    pages: [...element.querySelectorAll('.pdf-reader-page')].map((sheet) => ({
                        page: sheet.dataset.page,
                        rendered: sheet.dataset.rendered,
                        canvas: Boolean(sheet.querySelector('canvas')),
                    })),
                })),
            );
            throw error;
        });
    await expect(reader.locator('.pdf-reader-page-count')).toHaveText(/1\s*\/\s*[1-9]\d*/);
    await expect(reader.locator('.pdf-reader-text').first()).not.toBeEmpty();
    return reader;
}
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
    const context = await browser.newContext();
    context.on('page', (page) => {
        page.on('console', (message) => {
            if (message.type() === 'error') errors.push(message.text());
        });
        page.on('pageerror', (error) => pageErrors.push(error.message));
    });
    const page = await login(context, fixture);
    const application = await page.goto(`${base}/applications/${fixture.application_id}`);
    expect(application.headers()['x-frame-options']).toBe('DENY');
    expect(application.headers()['content-security-policy']).toContain("frame-ancestors 'none'");
    for (const [index, documentId] of [fixture.pdf_id, fixture.letter_id].entries()) {
        const opened = context.waitForEvent('page');
        await page.getByRole('link', { name: 'Review attachment' }).nth(index).click();
        const review = await opened;
        await review.waitForLoadState();
        const reader = review.locator('.pdf-reader');
        await expect(reader).toHaveAttribute(
            'data-source',
            `/documents/${documentId}/download?preview=1`,
        );
        const preview = await context.request.get(
            `${base}/documents/${documentId}/download?preview=1`,
        );
        expect(preview.status()).toBe(200);
        expect(preview.headers()['content-type']).toBe('application/pdf');
        expect((await preview.body()).subarray(0, 5).toString()).toBe('%PDF-');
        expect(preview.headers()['content-disposition']).toMatch(/^inline;/);
        expect(preview.headers()['x-frame-options']).toBe('SAMEORIGIN');
        expect(preview.headers()['content-security-policy']).toContain("frame-ancestors 'self'");
        expect(preview.headers()['content-security-policy']).not.toContain('sandbox');
        await expectRenderedPdf(review);
        expect(await review.locator('iframe, embed').count()).toBe(0);
        await expect(reader.locator('.pdf-reader-controls')).toBeVisible();
        const canvasBounds = await reader.locator('.pdf-reader-canvas').first().boundingBox();
        const controlBounds = await reader.locator('.pdf-reader-controls').boundingBox();
        expect(controlBounds.y).toBeGreaterThan(canvasBounds.y + 100);
        expect(controlBounds.y).toBeGreaterThanOrEqual(0);
        expect(controlBounds.y + controlBounds.height).toBeLessThanOrEqual(
            review.viewportSize().height + 1,
        );
        await expect(
            reader.getByRole('button', { name: 'Previous page', exact: true }),
        ).toBeDisabled();
        const pageCount = Number(
            (await reader.locator('.pdf-reader-page-count').textContent()).split('/')[1],
        );
        if (pageCount === 1) {
            await expect(
                reader.getByRole('button', { name: 'Next page', exact: true }),
            ).toBeDisabled();
        } else {
            await reader.getByRole('button', { name: 'Next page', exact: true }).click();
            await expect(reader.locator('.pdf-reader-page-count')).toHaveText(
                new RegExp(`2\\s*\\/\\s*${pageCount}`),
            );
            await reader.getByRole('button', { name: 'Previous page', exact: true }).click();
            await expect(reader.locator('.pdf-reader-page-count')).toHaveText(
                new RegExp(`1\\s*\\/\\s*${pageCount}`),
            );
            const outerScroll = await review.evaluate(() => window.scrollY);
            await reader.locator('.pdf-reader-pages').hover();
            await review.mouse.wheel(0, 500);
            await expect
                .poll(() =>
                    reader.locator('.pdf-reader-pages').evaluate((element) => element.scrollTop),
                )
                .toBeGreaterThan(0);
            expect(await review.evaluate(() => window.scrollY)).toBe(outerScroll);
            await reader.getByRole('button', { name: 'Previous page', exact: true }).click();
        }
        const textLayer = reader.locator('.pdf-reader-text').first();
        const selected = await textLayer.evaluate((element) => {
            const range = document.createRange();
            range.selectNodeContents(element);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(range);
            const text = selection.toString().trim();
            selection.removeAllRanges();
            return { text, userSelect: getComputedStyle(element).userSelect };
        });
        expect(selected.text.length).toBeGreaterThan(0);
        expect(selected.userSelect).not.toBe('none');

        if (index === 0) {
            await reader.getByRole('button', { name: 'Zoom in', exact: true }).click();
            await expect
                .poll(
                    async () =>
                        (await reader.locator('.pdf-reader-canvas').first().boundingBox()).width,
                )
                .toBeGreaterThan(canvasBounds.width);
            await reader.getByRole('button', { name: 'Zoom out', exact: true }).click();
            await reader.getByRole('button', { name: 'Fit page', exact: true }).click();
            await reader.evaluate((element) => {
                const zoom = element.querySelector('button[aria-label="Zoom in"]');
                const fit = element.querySelector('button[aria-label="Fit page"]');
                // Dispatch without render waits to exercise cancellation while a page is drawing.
                for (let i = 0; i < 4; i++) {
                    zoom.click();
                    zoom.click();
                    fit.click();
                }
            });
            await expect(
                reader.getByRole('button', { name: 'Fit page', exact: true }),
            ).toHaveAttribute('aria-pressed', 'true');
            await expect(reader.locator('.pdf-reader-page').first()).toHaveAttribute(
                'data-rendered',
                'true',
                { timeout: 30000 },
            );
            await expectRenderedPdf(review);
            await reader.getByRole('button', { name: 'Expand preview', exact: true }).click();
            await expect(
                reader.getByRole('button', { name: 'Exit expanded preview', exact: true }),
            ).toBeVisible();
            await expect(reader).toHaveClass(/\bis-expanded\b/);
            await expect(reader).toHaveAttribute('aria-modal', 'true');
            await expect(
                reader.getByRole('button', { name: 'Exit expanded preview', exact: true }),
            ).toBeFocused();
            await review.keyboard.press('Tab');
            await expect(reader.locator('.pdf-reader-pages')).toBeFocused();
            await review.keyboard.press('Shift+Tab');
            await expect(
                reader.getByRole('button', { name: 'Exit expanded preview', exact: true }),
            ).toBeFocused();
            for (let i = 0; i < 3; i++) {
                await review.keyboard.press('Tab');
                expect(
                    await reader.evaluate((element) => element.contains(document.activeElement)),
                ).toBe(true);
            }
            await review.keyboard.press('Escape');
            await expect(reader).not.toHaveClass(/\bis-expanded\b/);
            await expect(
                reader.getByRole('button', { name: 'Expand preview', exact: true }),
            ).toBeFocused();
            await reader.getByRole('button', { name: 'Expand preview', exact: true }).click();
            await reader
                .getByRole('button', { name: 'Exit expanded preview', exact: true })
                .click();
            await expect(
                reader.getByRole('button', { name: 'Expand preview', exact: true }),
            ).toBeVisible();

            await review.setViewportSize({ width: 390, height: 844 });
            await reader.getByRole('button', { name: 'Fit page', exact: true }).click();
            await expect
                .poll(async () => {
                    const canvas = await reader.locator('.pdf-reader-canvas').first().boundingBox();
                    const viewport = await reader.locator('.pdf-reader-pages').boundingBox();
                    return canvas.width <= viewport.width + 1;
                })
                .toBe(true);
            await expect(reader.locator('.pdf-reader-controls')).toBeVisible();
            expect(
                await review.evaluate(() => document.documentElement.scrollWidth),
            ).toBeLessThanOrEqual(390);
            await review.setViewportSize({ width: 1280, height: 720 });
            for (const size of [
                { width: 1366, height: 768 },
                { width: 1024, height: 600 },
                { width: 390, height: 844 },
                { width: 360, height: 640 },
                { width: 844, height: 390 },
            ]) {
                await review.setViewportSize(size);
                await reader.getByRole('button', { name: 'Fit page', exact: true }).click();
                await expect
                    .poll(async () => {
                        const bounds = await reader
                            .locator('.pdf-reader-page')
                            .first()
                            .boundingBox();
                        const viewport = await reader.locator('.pdf-reader-pages').boundingBox();
                        const controls = await reader.locator('.pdf-reader-controls').boundingBox();
                        return (
                            bounds.width <= viewport.width + 1 &&
                            bounds.height <= viewport.height + 1 &&
                            bounds.y >= viewport.y - 1 &&
                            bounds.y + bounds.height <= viewport.y + viewport.height + 1 &&
                            bounds.y + bounds.height <= controls.y - 4
                        );
                    })
                    .toBe(true);
                expect(
                    await review.evaluate(
                        () =>
                            document.documentElement.scrollHeight <= innerHeight + 1 &&
                            document.documentElement.scrollWidth <= innerWidth + 1,
                    ),
                ).toBe(true);
            }
            await review.setViewportSize({ width: 390, height: 844 });
            await review.getByRole('button', { name: 'Details', exact: true }).click();
            await expect(
                review.getByRole('textbox', { name: 'Document name', exact: true }),
            ).toBeVisible();
            await review.getByRole('button', { name: 'Close details', exact: true }).click();
            await review.setViewportSize({ width: 1280, height: 720 });
            await reader.getByRole('button', { name: 'Fit page', exact: true }).click();
            await expect(reader.locator('.pdf-reader-page').first()).toHaveAttribute(
                'data-rendered',
                'true',
            );
        }
        await mkdir('tests/.runtime', { recursive: true });
        await review.screenshot({
            path: `tests/.runtime/document-preview-${index === 0 ? 'cv' : 'letter'}.png`,
        });
        await review.close();
    }
    expect(errors).toEqual([]);
    expect(pageErrors).toEqual([]);
    pass('CV and cover-letter PDFs render with selectable text and compact bottom controls');
    pass('PDF zoom, fit-page, expanded preview, and mobile layout work');
    pass(
        'whole PDF pages fit short and mobile screens; multi-page scrolling stays inside the reader',
    );
    pass('rapid zoom changes render correctly and expanded keyboard focus stays inside the reader');
    pass('application pages retain their protection against framing');

    const failedPreview = `${base}/documents/${fixture.pdf_id}/download?preview=1`;
    await context.route(failedPreview, (route) => route.abort('failed'));
    const retryPage = await context.newPage();
    await retryPage.goto(`${base}/documents/${fixture.pdf_id}`);
    await expect(retryPage.locator('.pdf-reader').getByRole('alert')).toBeVisible();
    await expect(retryPage.getByRole('button', { name: 'Try again', exact: true })).toBeVisible();
    await context.unroute(failedPreview);
    await retryPage.getByRole('button', { name: 'Try again', exact: true }).click();
    await expectRenderedPdf(retryPage);
    await retryPage.close();
    pass('a failed PDF request shows an accessible error and recovers when retried');

    const download = await context.request.get(`${base}/documents/${fixture.pdf_id}/download`);
    expect(download.headers()['content-disposition']).toMatch(/^attachment;/);
    expect(download.headers()['x-frame-options']).toBe('DENY');
    expect(download.headers()['content-security-policy']).toContain('sandbox');
    const inline = await context.request.get(
        `${base}/documents/${fixture.pdf_id}/download?preview=1`,
    );
    expect(await download.body()).toEqual(await inline.body());
    const docx = await context.request.get(
        `${base}/documents/${fixture.document_id}/download?preview=1`,
    );
    expect(docx.headers()['content-disposition']).toMatch(/^attachment;/);
    expect(docx.headers()['x-frame-options']).toBe('DENY');
    pass('downloads preserve the PDF bytes and DOCX files remain attachment-only');

    const guest = await browser.newContext();
    const denied = await guest.request.get(
        `${base}/documents/${fixture.pdf_id}/download?preview=1`,
        {
            maxRedirects: 0,
        },
    );
    expect([302, 303]).toContain(denied.status());
    await guest.close();
    other = JSON.parse(
        execFileSync('php', ['tests/browser-fixtures.php', 'setup'], { env, encoding: 'utf8' }),
    );
    const otherContext = await browser.newContext();
    await login(otherContext, other);
    const anotherAccount = await otherContext.request.get(
        `${base}/documents/${fixture.pdf_id}/download?preview=1`,
    );
    expect(anotherAccount.status()).toBe(404);
    await otherContext.close();
    pass('PDF previews require sign-in and cannot be read by another account');

    external = createServer((request, response) => {
        response.setHeader('Content-Type', 'text/html');
        response.end(
            `<iframe src="${base}/documents/${fixture.pdf_id}/download?preview=1"></iframe>`,
        );
    });
    await new Promise((resolve) => external.listen(0, '127.0.0.1', resolve));
    const externalPage = await context.newPage();
    await externalPage.goto(`http://127.0.0.1:${external.address().port}`);
    await expect
        .poll(() => errors.some((error) => /frame-ancestors|X-Frame-Options/.test(error)))
        .toBe(true);
    expect(
        externalPage.frames().some((frame) => frame.url() === 'chrome-error://chromewebdata/'),
    ).toBe(true);
    pass('another website origin cannot embed private PDF previews');
    console.log(`${checks} document preview browser checks passed.`);
} finally {
    if (browser) await browser.close();
    if (external) await new Promise((resolve) => external.close(resolve));
    server.kill();
    for (const account of [fixture, other].filter(Boolean)) {
        execFileSync('php', ['tests/browser-fixtures.php', 'cleanup', String(account.id)], { env });
    }
}
