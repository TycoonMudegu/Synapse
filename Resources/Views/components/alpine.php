<?php // Shared Alpine components are rendered inline. Page-specific behavior lives in its view. ?>
<script nonce="<?= e(CSP_NONCE); ?>">
document.addEventListener('alpine:init', () => {
    Alpine.store('app', {
        toasts: [],
        noticeSequence: 0,
        taskText: '',
        taskSpinning: false,
        taskGeneration: 0,
        url(path) {
            const base = document.querySelector('meta[name="app-base-path"]')?.content ?? '';
            return `${base}/${path.replace(/^\/+/, '')}`;
        },
        icons() { window.lucide?.createIcons(); },
        notify(message, error = false) {
            const id = ++this.noticeSequence;
            this.toasts.push({ id, message, error });
            window.setTimeout(() => this.dismiss(id), error ? 18000 : 10000);
        },
        dismiss(id) { this.toasts = this.toasts.filter(item => item.id !== id); },
        openDialog(id) { document.getElementById(id)?.showModal(); },
        closeDialog(button) { button.closest('dialog')?.close(); },
        restoreSelection(select) {
            if (select.dataset.selectedValue) select.value = select.dataset.selectedValue;
        },
        async request(url, { method = 'POST', data = null } = {}) {
            <?php if (\App\Services\Documents\DocumentStorage::enabled()): ?>
            if (data instanceof FormData) {
                const fields = [...data.entries()].filter(([, value]) => value instanceof File);
                for (const [field, file] of fields) {
                    data.delete(field);
                    if (!file.size) continue;
                    const kind = field === 'cv' ? 'master_cv' : field === 'letter' ? 'cover_letter_template' : data.get('kind');
                    const { upload } = await this.request(this.url('/api/documents/uploads'), {
                        data: { name: file.name, size: file.size, kind },
                    });
                    let stored;
                    const uploadController = new AbortController();
                    const uploadTimeout = window.setTimeout(() => uploadController.abort(), 150000);
                    try {
                        stored = await fetch(upload.url, { method: upload.method, headers: upload.headers, body: file, credentials: 'omit', referrerPolicy: 'no-referrer', signal: uploadController.signal });
                    } catch { throw new Error('The document upload was interrupted or timed out. Please try again.'); }
                    finally { window.clearTimeout(uploadTimeout); }
                    if (!stored.ok) throw new Error('Cloud storage could not accept this upload. Please try again.');
                    const result = await this.request(this.url('/api/documents/uploads/complete'), {
                        data: { upload_id: upload.upload_id, inline: data.get('inline') || (field !== 'file' ? '1' : '') },
                    });
                    if (field === 'file') return result;
                    data.set(`${field}_document_id`, result.document.id);
                }
            }
            <?php endif; ?>
            const headers = {
                Accept: 'application/json',
                'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            };
            let body = data;
            if (data !== null && !(data instanceof FormData)) {
                headers['Content-Type'] = 'application/json';
                body = JSON.stringify(data);
            }
            const controller = new AbortController();
            const timeout = window.setTimeout(() => controller.abort(), 150000);
            let response;
            try {
                response = await fetch(url, {
                    method, headers, body: method === 'GET' ? null : body,
                    credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                });
            } catch (error) {
                throw new Error(error.name === 'AbortError'
                    ? 'The request timed out. Check its result before retrying.'
                    : 'The connection was interrupted. Check your network before trying again.');
            } finally { window.clearTimeout(timeout); }
            let result;
            try { result = await response.json(); }
            catch { throw new Error('The server returned an unexpected response. Please try again.'); }
            if (!response.ok || !result.ok) {
                if (response.status === 402) this.openDialog('upgrade-dialog');
                if (response.status === 409 && result.redirect) window.location.assign(result.redirect);
                if (response.status === 401) window.location.assign(this.url('/login'));
                const failure = new Error(result.message ?? 'This operation could not be completed.');
                failure.status = response.status;
                failure.data = result;
                throw failure;
            }
            return result;
        },
        async refreshCsrf() {
            const result = await this.request(this.url('/api/auth/csrf'), { method: 'GET' });
            if (typeof result.csrf_token !== 'string' || !/^[a-f0-9]{64}$/.test(result.csrf_token)) {
                throw new Error('Could not refresh your session. Reload this page and try again.');
            }
            document.querySelector('meta[name="csrf-token"]').content = result.csrf_token;
            return result.csrf_token;
        },
        async withLoading(button, callback) {
            if (!button || button.disabled) return;
            const original = [...button.childNodes];
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.textContent = button.dataset.loading ?? 'Working…';
            try { return await callback(); }
            finally {
                button.replaceChildren(...original);
                button.disabled = false;
                button.removeAttribute('aria-busy');
            }
        },
        formError(form, message) {
            const error = form.querySelector('.form-error');
            if (!error) return this.notify(message, true);
            error.textContent = message;
            error.hidden = false;
            error.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        },
        handleResult(result) {
            if (result.message) this.notify(result.message);
            if (result.task_id) this.trackTask(result.task_id);
            if (result.redirect) window.location.assign(result.redirect);
            else if (result.reload) window.location.reload();
        },
        trackTask(id) {
            if (!/^[0-9]+$/.test(String(id))) return;
            try { sessionStorage.setItem('jobflow-task', String(id)); } catch {}
            this.taskText = 'Your task is queued. We’ll update you as it progresses.';
            this.taskSpinning = true;
            this.pollTask(id, ++this.taskGeneration, 0);
        },
        async pollTask(id, generation, count) {
            try {
                const { data: task } = await this.request(this.url(`/api/tasks/${id}`), { method: 'GET' });
                if (generation !== this.taskGeneration) return;
                if (task.status === 'completed' || task.status === 'failed') {
                    try { sessionStorage.removeItem('jobflow-task'); } catch {}
                    this.taskSpinning = false;
                    if (task.status === 'failed') throw new Error(task.last_error || 'This task needs attention. Check Activity for details.');
                    this.taskText = 'Task completed. Updating your workspace…';
                    this.notify('Background task completed successfully.');
                    window.setTimeout(() => window.location.reload(), 900);
                    return;
                }
                const descriptions = {
                    discovery: 'Checking your job sites for new opportunities…',
                    analyse_job: 'Comparing this job with your verified experience…',
                    prepare_application: 'Preparing your CV and cover letter…',
                    submit_application: 'Sending through Gmail. Waiting for confirmation…',
                    gmail_sync: 'Checking your application correspondence…',
                    process_document: 'Reading your document and preparing its text…',
                };
                this.taskText = task.status === 'running'
                    ? (descriptions[task.type] || 'Working on your task…')
                    : (task.last_error ? `Waiting to retry: ${task.last_error}` : 'Queued. Your task will begin when a worker is available.');
                if (count < 100) window.setTimeout(() => this.pollTask(id, generation, count + 1), 3000);
                else {
                    this.taskSpinning = false;
                    this.taskText = 'This task is still queued or running. You can return later to check progress.';
                }
            } catch (error) {
                if (generation !== this.taskGeneration) return;
                this.taskSpinning = false;
                this.taskText = error.message;
                this.notify(error.message, true);
            }
        },
    });

    Alpine.data('appShell', () => ({
        menuOpen: false,
        plan: 'monthly',
        init() {
            this.$nextTick(() => {
                this.$store.app.icons();
                if (!document.getElementById('task-region')) return;
                try {
                    const id = sessionStorage.getItem('jobflow-task');
                    if (id) this.$store.app.trackTask(id);
                } catch {}
            });
        },
        toggleMenu() {
            this.menuOpen = !this.menuOpen;
            if (this.menuOpen) this.$nextTick(() => document.querySelector('#sidebar a')?.focus());
        },
    }));

    Alpine.data('asyncForm', () => ({
        busy: false,
        field: 'technology',
        provider: 'gemini',
        init() {
            this.field = this.$el.elements.namedItem('field_key')?.value || 'technology';
        },
        siteAllowed(element) {
            const field = element.closest('[data-source-field]')?.dataset.sourceField;
            return field === 'all' || field === this.field;
        },
        syncSource(input) { if (!this.siteAllowed(input)) input.checked = false; },
        async submit(event) {
            if (this.busy) return;
            const form = event.currentTarget;
            const button = event.submitter || form.querySelector('button[type="submit"], button:not([type])');
            const error = form.querySelector('.form-error');
            if (error) error.hidden = true;
            this.busy = true;
            try {
                await this.$store.app.withLoading(button, async () => {
                    try {
                        const data = new FormData(form);
                        if (form.hasAttribute('data-refresh-csrf')) data.set('_csrf', await this.$store.app.refreshCsrf());
                        this.$store.app.handleResult(await this.$store.app.request(form.action, { data }));
                    } catch (error) { this.$store.app.formError(form, error.message); }
                });
            } finally { this.busy = false; }
        },
    }));

    Alpine.data('actionButton', () => ({
        busy: false,
        async run() {
            const button = this.$el;
            if (this.busy || button.disabled) return;
            if (button.dataset.confirm && !window.confirm(button.dataset.confirm)) return;
            this.busy = true;
            try {
                await this.$store.app.withLoading(button, async () => {
                    try {
                        this.$store.app.handleResult(await this.$store.app.request(button.dataset.action, {
                            data: button.dataset.payload ? JSON.parse(button.dataset.payload) : {},
                        }));
                    } catch (error) { this.$store.app.notify(error.message, true); }
                });
            } finally { this.busy = false; }
        },
    }));

    Alpine.data('filterForm', () => ({
        async submit(event) {
            const form = event.currentTarget;
            const url = new URL(form.action || window.location.href);
            url.search = new URLSearchParams(new FormData(form)).toString();
            const region = document.querySelector('[data-filter-results]');
            region.setAttribute('aria-busy', 'true');
            try {
                const response = await fetch(url, { credentials: 'same-origin' });
                if (response.redirected) return window.location.assign(response.url);
                if (!response.ok) throw new Error('The filtered view could not be loaded.');
                const page = new DOMParser().parseFromString(await response.text(), 'text/html');
                const results = page.querySelector('[data-filter-results]');
                if (!results) throw new Error('The server returned an unexpected page.');
                region.replaceChildren(...results.childNodes);
                history.replaceState({}, '', url);
                this.$nextTick(() => this.$store.app.icons());
            } catch (error) { this.$store.app.notify(error.message, true); }
            finally { region.removeAttribute('aria-busy'); }
        },
    }));

    Alpine.data('disclosure', () => ({
        init() {
            try {
                const state = sessionStorage.getItem(`jobflow:details:${this.$el.dataset.persistDisclosure}`);
                if (state !== null) this.$el.open = state === 'open';
            } catch {}
        },
        save() {
            try { sessionStorage.setItem(`jobflow:details:${this.$el.dataset.persistDisclosure}`, this.$el.open ? 'open' : 'closed'); }
            catch {}
        },
    }));
});
</script>
<script defer src="<?= e(app_url('/Assets/lucide.js')); ?>"></script>
<script defer src="<?= e(app_url('/Assets/alpine.js')); ?>"></script>
