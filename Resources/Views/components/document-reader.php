<div
    class="pdf-reader"
    x-data="documentReader"
    x-bind:class="{ 'is-expanded': expanded }"
    x-bind:role="expanded ? 'dialog' : 'region'"
    x-bind:aria-modal="expanded ? 'true' : null"
    x-bind:aria-busy="loading"
    x-on:keydown="keyboard($event)"
    role="region"
    aria-label="PDF preview"
    data-source="<?= e(app_url('/documents/' . $document['id'] . '/download?preview=1')); ?>"
    data-assets="<?= e(app_url('/Assets/pdfjs/6.5.438/')); ?>"
>
    <div class="pdf-reader-pages" x-ref="pages" tabindex="0" aria-label="Document pages" x-on:scroll.passive="scrolled">
        <div class="pdf-reader-paper-stack" x-ref="paperStack"></div>
    </div>
    <div class="pdf-reader-status" x-bind:hidden="!loading" role="status">
        <span class="spinner" aria-hidden="true"></span>
        <span>Opening your document…</span>
    </div>
    <div class="pdf-reader-error" x-bind:hidden="!error" hidden role="alert">
        <span class="pdf-reader-error-icon"><?= icon('file-warning'); ?></span>
        <h2>We couldn’t open this preview</h2>
        <p x-text="error"></p>
        <button type="button" class="btn btn-primary" x-on:click="load">Try again</button>
        <a class="text-link" href="<?= e(app_url('/documents/' . $document['id'] . '/download')); ?>">Download document<?= icon('arrow-down-to-line'); ?></a>
    </div>
    <div class="pdf-reader-controls" x-bind:hidden="!expanded && (loading || error !== '')" hidden role="toolbar" aria-label="Document preview controls">
        <div class="pdf-reader-control-group">
            <button type="button" x-on:click="previous" x-bind:disabled="unavailable || page <= 1" aria-label="Previous page" title="Previous page"><?= icon('chevron-up'); ?></button>
            <span class="pdf-reader-page-count" aria-live="polite" aria-atomic="true" x-text="pageLabel">1 / 1</span>
            <button type="button" x-on:click="next" x-bind:disabled="unavailable || page >= total" aria-label="Next page" title="Next page"><?= icon('chevron-down'); ?></button>
        </div>
        <span class="pdf-reader-divider" aria-hidden="true"></span>
        <div class="pdf-reader-control-group">
            <button type="button" x-on:click="zoomOut" x-bind:disabled="unavailable || zoom <= 0.5" aria-label="Zoom out" title="Zoom out"><?= icon('minus'); ?></button>
            <button type="button" class="pdf-reader-fit" x-on:click="fit" x-bind:disabled="unavailable" aria-label="Fit page" title="Fit page" x-bind:aria-pressed="zoom === 1" x-text="zoomLabel">Fit</button>
            <button type="button" x-on:click="zoomIn" x-bind:disabled="unavailable || zoom >= 2" aria-label="Zoom in" title="Zoom in"><?= icon('plus'); ?></button>
        </div>
        <span class="pdf-reader-divider" aria-hidden="true"></span>
        <button type="button" x-ref="expand" x-on:click="toggleExpanded" x-bind:aria-label="expanded ? 'Exit expanded preview' : 'Expand preview'" x-bind:title="expanded ? 'Exit expanded preview' : 'Expand preview'" x-bind:aria-pressed="expanded" aria-label="Expand preview" aria-pressed="false">
            <span x-bind:hidden="expanded"><?= icon('maximize-2'); ?></span>
            <span x-bind:hidden="!expanded" hidden><?= icon('minimize-2'); ?></span>
        </button>
    </div>
    <noscript><p class="pdf-reader-no-script">Enable JavaScript to preview this PDF, or use Download to read it.</p></noscript>
</div>
<script nonce="<?= e(CSP_NONCE); ?>">
document.addEventListener('alpine:init', () => {
    Alpine.data('documentReader', () => {
        // PDF.js objects use private fields and must stay outside Alpine's reactive proxy.
        let root, library, documentTask, pdf, controller, observer, resizeObserver, resizeTimer;
        let sheets = [], generation = 0, active = true, lastWidth = 0, lastHeight = 0, renderKey = '', previousOverflow = '', inertSiblings = [];
        const cancelled = error => ['RenderingCancelledException', 'AbortException', 'AbortError'].includes(error.name);
        return {
            loading: true,
            error: '',
            expanded: false,
            page: 1,
            total: 0,
            zoom: 1,
            scale: 1,
            get unavailable() { return this.loading || this.error !== ''; },
            get pageLabel() { return this.total ? `${this.page} / ${this.total}` : '—'; },
            get zoomLabel() { return this.zoom === 1 ? 'Fit' : `${Math.round(this.scale * 100)}%`; },
            init() {
                root = this.$el;
                resizeObserver = new ResizeObserver(() => {
                    if (Math.abs(this.$refs.pages.clientWidth - lastWidth) < 2 && Math.abs(this.$refs.pages.clientHeight - lastHeight) < 2) return;
                    window.clearTimeout(resizeTimer);
                    resizeTimer = window.setTimeout(() => this.layout(), 100);
                });
                resizeObserver.observe(this.$refs.pages);
                this.load();
            },
            async load() {
                const run = ++generation;
                controller?.abort();
                observer?.disconnect();
                const previousTask = documentTask;
                documentTask = null;
                await previousTask?.destroy().catch(() => {});
                if (!active || generation !== run) return;
                pdf = null;
                sheets = [];
                this.$refs.paperStack.replaceChildren();
                this.loading = true;
                this.error = '';
                this.page = 1;
                this.total = 0;
                const fetchController = new AbortController();
                controller = fetchController;
                const timeout = window.setTimeout(() => fetchController.abort(), 90000);
                try {
                    const assets = root.dataset.assets;
                    const [module, response] = await Promise.all([
                        library ? Promise.resolve(library) : import(`${assets}pdf.min.mjs`),
                        fetch(root.dataset.source, {
                            credentials: 'same-origin', cache: 'no-store',
                            referrerPolicy: 'no-referrer', signal: fetchController.signal,
                        }),
                    ]);
                    library = module;
                    if (!response.ok) throw new Error('Download unavailable');
                    const data = new Uint8Array(await response.arrayBuffer());
                    if (!active || generation !== run) return;
                    library.GlobalWorkerOptions.workerSrc = `${assets}pdf.worker.min.mjs`;
                    documentTask = library.getDocument({
                        data, cMapUrl: `${assets}cmaps/`, cMapPacked: true,
                        standardFontDataUrl: `${assets}standard_fonts/`, wasmUrl: `${assets}wasm/`,
                        useWasm: false, disableWorkerRendering: true, enableXfa: false,
                    });
                    pdf = await documentTask.promise;
                    if (!active || generation !== run) return;
                    this.total = pdf.numPages;
                    const first = await pdf.getPage(1);
                    const viewport = first.getViewport({ scale: 1 });
                    const fragment = document.createDocumentFragment();
                    for (let number = 1; number <= this.total; number++) {
                        const element = document.createElement('div');
                        element.className = 'pdf-reader-page';
                        element.dataset.page = String(number);
                        element.setAttribute('role', 'group');
                        element.setAttribute('aria-label', `Page ${number} of ${this.total}`);
                        fragment.append(element);
                        sheets.push({ number, element, width: viewport.width, height: viewport.height, rendered: 0, running: false, task: null, text: null });
                    }
                    sheets[0].pdfPage = first;
                    this.$refs.paperStack.append(fragment);
                    this.layout(false);
                    observer = new IntersectionObserver(entries => {
                        for (const entry of entries) {
                            if (entry.isIntersecting) this.draw(sheets[Number(entry.target.dataset.page) - 1]);
                        }
                    }, { root: this.$refs.pages, rootMargin: '400px 0px' });
                    sheets.forEach(sheet => observer.observe(sheet.element));
                    await this.draw(sheets[0]);
                } catch (error) {
                    if (!active || generation !== run) return;
                    this.loading = false;
                    this.error = error.name === 'PasswordException'
                        ? 'This PDF needs a password. Download it to open it in your preferred PDF app.'
                        : 'Check your connection and try again. You can also download the original document.';
                } finally { window.clearTimeout(timeout); }
            },
            layout(keepPosition = true) {
                if (!sheets.length) return;
                const scroller = this.$refs.pages;
                const current = sheets[this.page - 1];
                const offset = current ? (scroller.scrollTop - current.element.offsetTop) / current.element.offsetHeight : 0;
                lastWidth = scroller.clientWidth;
                lastHeight = scroller.clientHeight;
                renderKey = `${lastWidth}:${lastHeight}:${this.zoom}`;
                this.scale = this.pageScale(current || sheets[0]);
                for (const sheet of sheets) {
                    if (sheet.running && sheet.drawKey !== renderKey) {
                        sheet.task?.cancel();
                        sheet.text?.cancel();
                    }
                    const pageScale = this.pageScale(sheet);
                    sheet.element.style.width = `${sheet.width * pageScale}px`;
                    sheet.element.style.height = `${sheet.height * pageScale}px`;
                    sheet.element.style.setProperty('--total-scale-factor', pageScale);
                }
                if (keepPosition && current) scroller.scrollTop = current.element.offsetTop + offset * current.element.offsetHeight;
                this.renderVisible();
            },
            pageScale(sheet) {
                const padding = getComputedStyle(this.$refs.paperStack);
                const width = this.$refs.pages.clientWidth - parseFloat(padding.paddingLeft) - parseFloat(padding.paddingRight);
                const height = this.$refs.pages.clientHeight - parseFloat(padding.paddingTop) - parseFloat(padding.paddingBottom);
                return Math.max(0.02, Math.min(width / sheet.width, height / sheet.height)) * this.zoom;
            },
            async draw(sheet) {
                if (!sheet || sheet.running || sheet.rendered === renderKey || !pdf) return;
                const run = generation;
                const key = renderKey;
                sheet.running = true;
                sheet.drawKey = key;
                let retry = false;
                try {
                    sheet.pdfPage ??= await pdf.getPage(sheet.number);
                    if (!active || generation !== run) return;
                    if (key !== renderKey) { retry = true; return; }
                    const base = sheet.pdfPage.getViewport({ scale: 1 });
                    sheet.width = base.width;
                    sheet.height = base.height;
                    const viewport = sheet.pdfPage.getViewport({ scale: this.pageScale(sheet) });
                    if (sheet.number === this.page) this.scale = viewport.scale;
                    sheet.element.style.width = `${viewport.width}px`;
                    sheet.element.style.height = `${viewport.height}px`;
                    sheet.element.style.setProperty('--total-scale-factor', viewport.scale);
                    const canvas = document.createElement('canvas');
                    canvas.className = 'pdf-reader-canvas';
                    canvas.setAttribute('aria-hidden', 'true');
                    const density = Math.min(window.devicePixelRatio || 1, 2, Math.sqrt(5000000 / (viewport.width * viewport.height)));
                    canvas.width = Math.max(1, Math.floor(viewport.width * density));
                    canvas.height = Math.max(1, Math.floor(viewport.height * density));
                    const text = document.createElement('div');
                    text.className = 'pdf-reader-text';
                    sheet.rendered = 0;
                    sheet.element.replaceChildren(canvas, text);
                    sheet.element.dataset.rendered = 'false';
                    sheet.task = sheet.pdfPage.render({ canvas, viewport, transform: [density, 0, 0, density, 0, 0] });
                    await sheet.task.promise;
                    sheet.text = new library.TextLayer({ textContentSource: sheet.pdfPage.streamTextContent(), container: text, viewport });
                    await sheet.text.render();
                    sheet.rendered = key;
                    sheet.element.dataset.rendered = 'true';
                    if (sheet.number === 1) this.loading = false;
                } catch (error) {
                    if (cancelled(error)) retry = true;
                    if (!cancelled(error) && active && generation === run) {
                        this.loading = false;
                        this.error = 'This page could not be displayed. Try again or download the original document.';
                    }
                } finally {
                    sheet.task = null;
                    sheet.text = null;
                    sheet.running = false;
                    if (active && generation === run && (retry || key !== renderKey)) {
                        window.requestAnimationFrame(() => { if (active) this.renderVisible(); });
                    }
                }
            },
            renderVisible() {
                const bounds = this.$refs.pages.getBoundingClientRect();
                for (const sheet of sheets) {
                    const rect = sheet.element.getBoundingClientRect();
                    if (rect.bottom >= bounds.top - 400 && rect.top <= bounds.bottom + 400) this.draw(sheet);
                    else if (!sheet.running && sheet.element.querySelector('canvas')) {
                        // Keep long documents from retaining a canvas for every visited page.
                        const canvas = sheet.element.querySelector('canvas');
                        canvas.width = canvas.height = 0;
                        sheet.element.replaceChildren();
                        sheet.pdfPage?.cleanup();
                        sheet.rendered = 0;
                        sheet.element.dataset.rendered = 'false';
                    }
                }
            },
            scrolled() {
                const scroller = this.$refs.pages;
                const marker = scroller.scrollTop + Math.min(scroller.clientHeight * 0.3, 200);
                let nearest = 1;
                for (const sheet of sheets) {
                    if (sheet.element.offsetTop <= marker) nearest = sheet.number;
                    else break;
                }
                this.page = nearest;
                if (sheets[nearest - 1]) this.scale = this.pageScale(sheets[nearest - 1]);
                this.renderVisible();
            },
            go(number) {
                const sheet = sheets[number - 1];
                if (!sheet) return;
                this.page = number;
                this.$refs.pages.scrollTo({ top: Math.max(0, sheet.element.offsetTop - parseFloat(getComputedStyle(this.$refs.paperStack).paddingTop)), behavior: 'instant' });
                this.renderVisible();
            },
            previous() { this.go(this.page - 1); },
            next() { this.go(this.page + 1); },
            zoomIn() { this.zoom = Math.min(2, +(this.zoom + 0.25).toFixed(2)); this.layout(); },
            zoomOut() { this.zoom = Math.max(0.5, +(this.zoom - 0.25).toFixed(2)); this.layout(); },
            fit() { this.zoom = 1; this.layout(); },
            toggleExpanded() {
                this.expanded = !this.expanded;
                if (this.expanded) {
                    previousOverflow = document.body.style.overflow;
                    document.body.style.overflow = 'hidden';
                    let branch = root;
                    while (branch.parentElement) {
                        for (const sibling of branch.parentElement.children) {
                            if (sibling !== branch && sibling instanceof HTMLElement) {
                                inertSiblings.push([sibling, sibling.inert]);
                                sibling.inert = true;
                            }
                        }
                        branch = branch.parentElement;
                        if (branch === document.body) break;
                    }
                } else {
                    document.body.style.overflow = previousOverflow;
                    inertSiblings.forEach(([element, inert]) => { element.inert = inert; });
                    inertSiblings = [];
                }
                this.$nextTick(() => { this.layout(); this.$refs.expand.focus(); });
            },
            keyboard(event) {
                if (event.key === 'Escape' && this.expanded) { event.preventDefault(); this.toggleExpanded(); }
                if (event.target === this.$refs.pages && !event.ctrlKey && !event.metaKey) {
                    if (event.key === 'ArrowRight' || event.key === 'PageDown') { event.preventDefault(); this.next(); }
                    if (event.key === 'ArrowLeft' || event.key === 'PageUp') { event.preventDefault(); this.previous(); }
                    if (event.key === 'Home') { event.preventDefault(); this.go(1); }
                    if (event.key === 'End') { event.preventDefault(); this.go(this.total); }
                }
                if (event.key === 'Tab' && this.expanded) {
                    const controls = [...root.querySelectorAll('button:not(:disabled), a[href], [tabindex="0"]')].filter(element => element.checkVisibility());
                    const first = controls[0], last = controls[controls.length - 1];
                    if (event.shiftKey && event.target === first) { event.preventDefault(); last?.focus(); }
                    else if (!event.shiftKey && event.target === last) { event.preventDefault(); first?.focus(); }
                }
            },
            destroy() {
                active = false;
                generation++;
                controller?.abort();
                observer?.disconnect();
                resizeObserver?.disconnect();
                window.clearTimeout(resizeTimer);
                documentTask?.destroy().catch(() => {});
                if (this.expanded) document.body.style.overflow = previousOverflow;
                inertSiblings.forEach(([element, inert]) => { element.inert = inert; });
            },
        };
    });
});
</script>
