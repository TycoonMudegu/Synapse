import { chromium, expect } from '@playwright/test';
import { spawn, execFileSync } from 'node:child_process';
import { readFile, readdir, mkdir } from 'node:fs/promises';
import { randomBytes } from 'node:crypto';

if (process.env.JOBFLOW_CLOUD_TEST !== '1') {
    throw new Error(
        'This opt-in integration test writes and removes private test objects. Set JOBFLOW_CLOUD_TEST=1.',
    );
}
const base = 'http://127.0.0.1:8857';
const database = process.env.JOBFLOW_TEST_DATABASE || 'jobflow_cloud_test';
if (!/^[a-zA-Z0-9_]+_test$/.test(database))
    throw new Error('Use a separate database ending in _test.');
const env = {
    ...process.env,
    JOBFLOW_TEST_DATABASE: database,
    DB_DATABASE: database,
    DOCUMENT_STORAGE: 'gcs',
    GCS_OBJECT_PREFIX: `integration/${randomBytes(8).toString('hex')}`,
    APP_URL: base,
    SESSION_SECURE: 'false',
    GEMINI_API_KEY: '',
    SMTP_PASSWORD: '',
};
const fixture = JSON.parse(
    execFileSync('php', ['tests/browser-fixtures.php', 'setup-preview'], { env, encoding: 'utf8' }),
);
const server = spawn('php', ['-S', '127.0.0.1:8857', '-t', 'Public', 'Public/router.php'], {
    env,
    stdio: 'ignore',
});
const php = (code, args = []) =>
    execFileSync('php', ['-r', 'require "Bootstrap/App.php"; ' + code, ...args.map(String)], {
        env,
        encoding: 'utf8',
    });
let browser;
let other;
let checks = 0;
const pass = (message) => {
    checks++;
    console.log(`PASS ${message}`);
};
try {
    for (let attempt = 0; attempt < 40; attempt++) {
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
    const page = await context.newPage();
    const failures = [];
    page.on('pageerror', (error) => failures.push(error.message));
    await page.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
    await page.getByLabel('Email address', { exact: true }).fill(fixture.email);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await page.getByLabel('Password', { exact: true }).fill(fixture.password);
    await page.getByRole('button', { name: 'Continue', exact: true }).click();
    await expect(page).toHaveURL(`${base}/`);
    const pdfResponse = await context.request.get(`${base}/documents/${fixture.pdf_id}/download`);
    const pdf = await pdfResponse.body();
    const docx = await readFile(fixture.path);
    const before = (await readdir('storage/private')).sort();
    const requests = [];
    const previewRequests = [];
    page.on('request', (request) => {
        if (request.method() === 'POST' || request.method() === 'PUT') requests.push(request);
        if (
            request.method() === 'GET' &&
            (new URL(request.url()).hostname === 'storage.googleapis.com' ||
                /\/documents\/\d+\/download\?preview=1$/.test(request.url()))
        )
            previewRequests.push(request);
    });
    async function upload(name, mimeType, buffer, kind = 'supporting') {
        await page.goto(`${base}/documents`, { waitUntil: 'domcontentloaded' });
        await page.getByRole('button', { name: 'Upload document', exact: true }).click();
        const dialog = page.locator('#upload-dialog');
        await dialog.locator('select[name=kind]').selectOption(kind);
        await dialog.locator('input[type=file]').setInputFiles({ name, mimeType, buffer });
        const stored = page.waitForResponse(
            (response) => response.url() === `${base}/api/documents/uploads/complete`,
        );
        await dialog.getByRole('button', { name: 'Upload document', exact: true }).click();
        expect((await stored).status()).toBe(200);
        await expect(page).toHaveURL(new RegExp(`^${base}/documents/[0-9]+$`));
        return Number(new URL(page.url()).pathname.split('/').pop());
    }
    const docxId = await upload(
        'cloud-fixture.docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        docx,
    );
    const cloudRequest = requests.find(
        (request) =>
            request.method() === 'PUT' &&
            new URL(request.url()).hostname === 'storage.googleapis.com',
    );
    expect(cloudRequest).toBeDefined();
    const storedDocx = await context.request.get(`${base}/documents/${docxId}/download`);
    expect(await storedDocx.body()).toEqual(docx);
    expect(requests.some((request) => request.url() === `${base}/api/documents`)).toBe(false);
    for (const request of requests.filter((request) => new URL(request.url()).origin === base)) {
        expect(request.headers()['content-type']).not.toContain('multipart');
        expect(request.postDataBuffer()?.includes(docx)).toBe(false);
    }
    expect((await readdir('storage/private')).sort()).toEqual(before);
    pass(
        'browser sends DOCX bytes only to Google; PHP receives JSON metadata and saves no local file',
    );

    const csrf = await page.locator('meta[name=csrf-token]').getAttribute('content');
    const unsigned = await context.request.post(`${base}/api/documents/uploads`, {
        data: { name: 'bad.php', kind: 'supporting', size: 10 },
    });
    expect(unsigned.status()).toBe(403);
    const invalid = await context.request.post(`${base}/api/documents/uploads`, {
        headers: { 'X-CSRF-Token': csrf },
        data: { name: 'bad.php', kind: 'supporting', size: 10 },
    });
    expect(invalid.status()).toBe(422);
    const replay = await context.request.put(cloudRequest.url(), {
        headers: {
            'Content-Type':
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'x-goog-if-generation-match': '0',
        },
        data: docx,
    });
    expect(replay.status()).toBe(412);
    const changedSize = await context.request.put(cloudRequest.url(), {
        headers: {
            'Content-Type':
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'x-goog-if-generation-match': '0',
        },
        data: Buffer.from('different-size'),
    });
    expect(changedSize.status()).toBe(403);
    pass('upload authorization enforces CSRF, formats, signed byte length, and create-only writes');

    php(
        '(new App\\Services\\Documents\\DocumentService())->process((int)$argv[1], (int)$argv[2]);',
        [fixture.id, docxId],
    );
    const processed = JSON.parse(
        php(
            '$d=(new App\\Repositories\\DocumentRepository())->get((int)$argv[1], (int)$argv[2]); echo json_encode(["status"=>$d["processing_status"],"text"=>strlen($d["extracted_text"]),"driver"=>$d["storage_driver"]]);',
            [fixture.id, docxId],
        ),
    );
    expect(processed.status).toBe('ready');
    expect(processed.driver).toBe('gcs');
    expect(processed.text).toBeGreaterThan(0);
    expect((await readdir('storage/private')).sort()).toEqual(before);
    pass(
        'background DOCX validation and extraction read cloud storage and retain no local document',
    );

    await context.addCookies([
        {
            name: 'synapse-preview-credential-probe',
            value: 'must-not-be-sent',
            domain: 'storage.googleapis.com',
            path: '/',
            secure: true,
            sameSite: 'None',
        },
    ]);
    const pdfId = await upload('cloud-preview.pdf', 'application/pdf', pdf);
    const redirect = await context.request.get(`${base}/documents/${pdfId}/download?preview=1`, {
        maxRedirects: 0,
    });
    expect(redirect.status()).toBe(303);
    const location = new URL(redirect.headers().location);
    expect(location.hostname).toBe('storage.googleapis.com');
    expect(location.searchParams.get('X-Goog-Expires')).toBe('300');
    expect(location.searchParams.has('generation')).toBe(true);
    expect((await redirect.body()).length).toBe(0);
    const viewed = await context.request.get(location.toString());
    expect(await viewed.body()).toEqual(pdf);
    expect(viewed.headers()['content-disposition']).toMatch(/^inline/);
    const reader = page.locator('.pdf-reader');
    await expect(reader)
        .toHaveAttribute('data-source', `/documents/${pdfId}/download?preview=1`)
        .catch(async (error) => {
            await mkdir('tests/.runtime', { recursive: true });
            await page.screenshot({ path: 'tests/.runtime/cloud-document-preview-failure.png' });
            throw error;
        });
    const canvas = reader.locator('.pdf-reader-canvas').first();
    await expect(canvas).toBeVisible({ timeout: 30000 });
    await expect
        .poll(() =>
            canvas.evaluate((element) => {
                if (element.width < 100 || element.height < 100) return false;
                const pixels = element
                    .getContext('2d')
                    .getImageData(0, 0, element.width, element.height).data;
                for (let i = 0; i < pixels.length; i += 16) {
                    if (pixels[i + 3] > 0 && pixels[i] + pixels[i + 1] + pixels[i + 2] < 600)
                        return true;
                }
                return false;
            }),
        )
        .toBe(true);
    await expect(reader.locator('.pdf-reader-text').first()).not.toBeEmpty();
    await expect(reader.locator('.pdf-reader-page-count')).toHaveText(/1\s*\/\s*[1-9]\d*/);
    await expect(reader.locator('.pdf-reader-controls')).toBeVisible();
    expect(await page.locator('iframe, embed').count()).toBe(0);

    const previewRequest = previewRequests.find(
        (request) => request.url() === `${base}/documents/${pdfId}/download?preview=1`,
    );
    expect(previewRequest).toBeDefined();
    expect(previewRequest.resourceType()).toBe('fetch');
    const browserRedirect = await previewRequest.response();
    expect(browserRedirect.status()).toBe(303);
    expect(browserRedirect.headers()['content-length'] || '0').toBe('0');
    const cloudPreview = previewRequests.find(
        (request) => request.redirectedFrom() === previewRequest,
    );
    expect(cloudPreview).toBeDefined();
    expect(new URL(cloudPreview.url()).hostname).toBe('storage.googleapis.com');
    expect((await cloudPreview.response()).status()).toBe(200);
    const cloudHeaders = await cloudPreview.allHeaders();
    expect(cloudHeaders.cookie).toBeUndefined();
    expect(cloudHeaders.authorization).toBeUndefined();
    expect(cloudHeaders.referer).toBeUndefined();
    await mkdir('tests/.runtime', { recursive: true });
    await page.screenshot({ path: 'tests/.runtime/cloud-document-preview.png' });
    pass(
        'custom PDF review fetches directly from Google without credentials through a short-lived generation-pinned URL',
    );

    const uploadBody = JSON.parse(
        requests
            .find((request) => request.url() === `${base}/api/documents/uploads/complete`)
            .postData(),
    );
    const completedAgain = await context.request.post(`${base}/api/documents/uploads/complete`, {
        headers: { 'X-CSRF-Token': csrf },
        data: uploadBody,
    });
    expect((await completedAgain.json()).document.id).toBe(docxId);
    other = JSON.parse(
        execFileSync('php', ['tests/browser-fixtures.php', 'setup'], { env, encoding: 'utf8' }),
    );
    const outsider = await browser.newContext();
    const outsiderPage = await outsider.newPage();
    await outsiderPage.goto(`${base}/login`, { waitUntil: 'domcontentloaded' });
    const outsiderCsrf = await outsiderPage
        .locator('meta[name=csrf-token]')
        .getAttribute('content');
    await outsider.request.post(`${base}/login`, {
        headers: { 'X-CSRF-Token': outsiderCsrf },
        data: { email: other.email, password: other.password },
    });
    await outsiderPage.goto(`${base}/documents`, { waitUntil: 'domcontentloaded' });
    expect(
        (
            await outsider.request.get(`${base}/documents/${pdfId}/download?preview=1`, {
                maxRedirects: 0,
            })
        ).status(),
    ).toBe(404);
    const rejectedComplete = await outsider.request.post(`${base}/api/documents/uploads/complete`, {
        headers: {
            'X-CSRF-Token': await outsiderPage
                .locator('meta[name=csrf-token]')
                .getAttribute('content'),
        },
        data: uploadBody,
    });
    expect(rejectedComplete.status()).toBe(404);
    await outsider.close();
    pass('upload completion is idempotent and document access remains scoped to its owner');

    const beforeOnboarding = (await readdir('storage/private')).sort();
    php(
        'App\\Core\\Database::connection()->prepare("UPDATE users SET onboarded_at=NULL,onboarding_step=2 WHERE id=?")->execute([(int)$argv[1]]);',
        [fixture.id],
    );
    await page.goto(`${base}/onboarding?step=2`, { waitUntil: 'domcontentloaded' });
    await page.locator('#cv-upload').setInputFiles({
        name: 'onboarding-cloud.docx',
        mimeType: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        buffer: docx,
    });
    await page
        .locator('#letter-upload')
        .setInputFiles({ name: 'onboarding-letter.pdf', mimeType: 'application/pdf', buffer: pdf });
    await page.getByRole('button', { name: 'Save and continue', exact: true }).click();
    await expect(page).toHaveURL(`${base}/onboarding?step=3`);
    const saved = JSON.parse(
        php(
            'echo json_encode((new App\\Repositories\\AccountRepository())->preferences((int)$argv[1]));',
            [fixture.id],
        ),
    );
    expect(Number(saved.cv_document_id)).toBeGreaterThan(0);
    expect(Number(saved.letter_document_id)).toBeGreaterThan(0);
    const onboardingRequest = requests.findLast(
        (request) => request.url() === `${base}/api/onboarding`,
    );
    expect(onboardingRequest.postDataBuffer().includes(docx)).toBe(false);
    expect(onboardingRequest.postDataBuffer().includes(pdf)).toBe(false);
    php(
        '$worker=new App\\Services\\Worker(["process_document"]); for($i=0;$i<10 && $worker->tick();$i++) {}',
    );
    const onboardingDoc = JSON.parse(
        php(
            'echo json_encode((new App\\Repositories\\DocumentRepository())->get((int)$argv[1],(int)$argv[2])["processing_status"]);',
            [fixture.id, saved.cv_document_id],
        ),
    );
    expect(onboardingDoc).toBe('ready');
    expect((await readdir('storage/private')).sort()).toEqual(beforeOnboarding);
    expect(failures).toEqual([]);
    pass('onboarding uploads both documents directly and the worker finishes CV processing');
    console.log(`${checks} live cloud storage browser checks passed.`);
} finally {
    if (browser) await browser.close();
    server.kill();
    for (const account of [fixture, other].filter(Boolean)) {
        execFileSync('php', ['tests/browser-fixtures.php', 'cleanup', String(account.id)], { env });
    }
}
