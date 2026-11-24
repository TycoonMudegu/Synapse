<div x-data="jobImport">
<div class="page-heading">
    <div>
        <div class="eyebrow mb-2">GOOD OPPORTUNITIES COME FROM EVERYWHERE</div>
        <h1><?= isset($editingJob) ? 'Edit opportunity' : 'Add an opportunity'; ?></h1>
        <p>A link, a poster, a few notes. Give your next possibility a place to grow.</p>
    </div>
    <a class="btn" href="<?= e(app_base_path()); ?>/jobs"><?= icon('arrow-left'); ?>My jobs</a>
</div>
<div class="import-tabs" role="tablist" aria-label="Add job method">
    <?php foreach (
        [
            ['manual', 'square-pen', 'Enter details'],
            ['url', 'link', 'Paste a link'],
            ['poster', 'image', 'Upload a poster'],
            ['text', 'align-left', 'Paste advert text'],
        ]
        as [$method, $iconName, $name]
    ): ?>
    <button
        type="button"
        class="import-tab <?= $method === 'manual' ? 'selected' : ''; ?>"
        id="tab-<?= e($method); ?>"
        x-on:click="choose($el.dataset.importTab)" x-on:keydown.arrow-left.prevent="moveTab($el, -1)" x-on:keydown.arrow-right.prevent="moveTab($el, 1)" x-on:keydown.home.prevent="edgeTab(false)" x-on:keydown.end.prevent="edgeTab(true)" x-bind:class="{ selected: method === $el.dataset.importTab }" x-bind:aria-selected="method === $el.dataset.importTab" x-bind:tabindex="method === $el.dataset.importTab ? 0 : -1" data-import-tab="<?= e($method); ?>"
        role="tab"
        aria-controls="import-<?= e($method); ?>"
        aria-selected="<?= $method === 'manual' ? 'true' : 'false'; ?>"
    >
        <?= icon($iconName); ?><?= e($name); ?>
    </button>
    <?php endforeach; ?>
</div>
<section class="panel mb-6" id="import-url" x-bind:hidden="method !== 'url'" role="tabpanel" aria-labelledby="tab-url" hidden>
    <div class="panel-header">
        <h2>Import a public vacancy URL</h2>
        <span class="badge badge-blue">AI provider required</span>
    </div>
    <form class="panel-body form-stack" x-on:submit.prevent="extract($event)">
        <input type="hidden" name="type" value="url" />
        <div class="form-error" hidden role="alert"></div>
        <div class="field">
            <label for="job-url">Job advertisement URL</label>
            <input
                id="job-url"
                name="url"
                type="url"
                placeholder="https://employer.example/careers/vacancy"
                required
            />
        </div>
        <label class="check-label">
            <input type="checkbox" name="permission_confirmed" value="1" required />
            I have confirmed that this public page permits automated access. Synapse will also check
            robots directives.
        </label>
        <button
            type="submit"
            class="btn btn-primary self-start"
            data-loading="Retrieving and extracting job details…"
        >
            <?= icon('scan-text'); ?>Extract details
        </button>
    </form>
</section>
<section class="panel mb-6" id="import-poster" x-bind:hidden="method !== 'poster'" role="tabpanel" aria-labelledby="tab-poster" hidden>
    <div class="panel-header">
        <h2>Bring the advertisement with you</h2>
        <span class="badge badge-blue">Vision-capable model for images</span>
    </div>
    <form class="panel-body form-stack" x-on:submit.prevent="extract($event, true)" enctype="multipart/form-data">
        <input type="hidden" name="kind" value="job_poster" />
        <input type="hidden" name="inline" value="1" />
        <div class="form-error" hidden role="alert"></div>
        <div class="upload-zone">
            <?= icon('image-up'); ?>
            <h3>Upload a job poster</h3>
            <p>JPG, PNG, WEBP or PDF. If extraction fails, enter the details below.</p>
            <input
                type="file"
                name="file"
                accept=".jpg,.jpeg,.png,.webp,.pdf"
                required
                aria-label="Choose a job poster"
            />
        </div>
        <button
            class="btn btn-primary self-start"
            type="submit"
            data-loading="Uploading and reading the advertisement…"
        >
            <?= icon('scan-text'); ?>Upload & extract
        </button>
    </form>
</section>
<section class="panel mb-6" id="import-text" x-bind:hidden="method !== 'text'" role="tabpanel" aria-labelledby="tab-text" hidden>
    <div class="panel-header">
        <h2>Paste the complete advertisement</h2>
        <span class="badge badge-blue">AI provider required</span>
    </div>
    <form class="panel-body form-stack" x-on:submit.prevent="extract($event)">
        <input type="hidden" name="type" value="text" />
        <div class="form-error" hidden role="alert"></div>
        <div class="field">
            <label for="advertisement">Advertisement text</label>
            <textarea
                name="text"
                id="advertisement"
                rows="7"
                placeholder="Paste the title, requirements, application instructions and any other details…"
                required
            ></textarea>
        </div>
        <button
            class="btn btn-primary self-start"
            type="submit"
            data-loading="Extracting job details…"
        >
            <?= icon('scan-text'); ?>Structure advertisement
        </button>
    </form>
</section>
<div class="notice warm" id="extraction-notice" hidden>
    <?= icon('clipboard-check'); ?>
    <div>
        <strong>These are extracted details, ready for your review.</strong>
        <p id="extraction-uncertainties">
            Verify all information against the original advertisement.
        </p>
    </div>
</div>
<section class="panel" id="import-manual" role="tabpanel" aria-labelledby="tab-manual">
    <div class="panel-header">
        <div>
            <h2>The opportunity, in a little more detail</h2>
            <p>Keep unknown facts blank. You can analyse the job after saving it.</p>
        </div>
    </div>
    <form action="<?= e(app_url(isset($editingJob) ? '/api/jobs/' . $editingJob['id'] : '/api/jobs')); ?>" method="post" class="panel-body" x-data="asyncForm" x-on:submit.prevent="submit($event)" id="job-details-form">
        <div class="form-error" hidden role="alert"></div>
        <input type="hidden" name="poster_document_id" value="" />
        <div class="form-grid">
            <div class="field">
                <label for="title">Job title *</label>
                <input
                    id="title"
                    name="title"
                    maxlength="250"
                    placeholder="The advertised role"
                    required value="<?= e($editingJob['title'] ?? ''); ?>"
                />
            </div>
            <div class="field">
                <label for="employer">Employer *</label>
                <input
                    id="employer"
                    name="employer"
                    maxlength="250"
                    placeholder="Company or organisation"
                    required value="<?= e($editingJob['employer'] ?? ''); ?>"
                />
            </div>
            <div class="field">
                <label for="location">Location</label>
                <input
                    id="location"
                    name="location"
                    placeholder="As supplied in the advertisement" value="<?= e($editingJob['location'] ?? ''); ?>"
                />
            </div>
            <div class="field">
                <label for="employment_type">Employment type</label>
                <select id="employment_type" name="employment_type" x-init="$store.app.restoreSelection($el)" data-selected-value="<?= e($editingJob['employment_type'] ?? ''); ?>">
                    <?php foreach (
                [
                    'not_specified',
                    'full_time',
                    'part_time',
                    'contract',
                    'internship',
                    'temporary',
                    'volunteer',
                ]
                as $type
            ): ?>
                    <option value="<?= e($type); ?>"><?= e(label($type)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field full-width">
                <label for="description">Job description *</label>
                <textarea
                    id="description"
                    name="description"
                    rows="7"
                    placeholder="What is the role about?"
                    required
                ><?= e($editingJob['description'] ?? ''); ?></textarea>
            </div>
            <?php foreach (
                [
                    'requirements' => 'Requirements',
                    'responsibilities' => 'Responsibilities',
                    'qualifications' => 'Qualifications',
                ]
                as $field => $name
            ): ?>
            <div class="field <?= $field === 'qualifications' ? 'full-width' : ''; ?>">
                <label for="<?= e($field); ?>"><?= e($name); ?></label>
                <textarea id="<?= e($field); ?>" name="<?= e($field); ?>" rows="4"><?= e($editingJob[$field] ?? ''); ?></textarea>
            </div>
            <?php endforeach; ?>
            <div class="field">
                <label for="deadline">Application deadline</label>
                <input type="date" id="deadline" name="deadline" value="<?= e($editingJob['deadline'] ?? ''); ?>" />
                <small>Leave blank if no deadline is advertised.</small>
            </div>
            <div class="field">
                <label for="workplace">Work arrangement</label>
                <select name="workplace" x-init="$store.app.restoreSelection($el)" data-selected-value="<?= e($editingJob['workplace'] ?? ''); ?>" id="workplace">
                    <?php foreach (
                ['not_specified', 'remote', 'hybrid', 'office']
                as $type
            ): ?>
                    <option value="<?= e($type); ?>"><?= e(label($type)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-grid full-width">
                <div class="field">
                    <label for="salary_min">Minimum advertised salary</label>
                    <input
                        type="number"
                        name="salary_min"
                        id="salary_min"
                        min="0"
                        step="0.01"
                        placeholder="Not supplied" value="<?= e($editingJob['salary_min'] ?? ''); ?>"
                    />
                </div>
                <div class="field">
                    <label for="salary_max">Maximum advertised salary</label>
                    <input
                        type="number"
                        name="salary_max"
                        id="salary_max"
                        min="0"
                        step="0.01"
                        placeholder="Not supplied" value="<?= e($editingJob['salary_max'] ?? ''); ?>"
                    />
                </div>
            </div>
            <div class="field">
                <label for="currency">Advertised currency</label>
                <input
                    name="currency"
                    id="currency"
                    placeholder="e.g. KES, if advertised"
                    maxlength="10" value="<?= e($editingJob['currency'] ?? ''); ?>"
                />
            </div>
            <div class="field">
                <label for="original_url">Original job URL</label>
                <input type="url" id="original_url" name="original_url" placeholder="https://…" value="<?= e($editingJob['original_url'] ?? ''); ?>" />
            </div>
            <div class="field">
                <label for="application_method">Application method</label>
                <select name="application_method" x-init="$store.app.restoreSelection($el)" data-selected-value="<?= e($editingJob['application_method'] ?? ''); ?>" id="application_method">
                    <option value="manual">Manual completion</option>
                    <option value="email">Email application</option>
                    <option value="external">Employer website / external form</option>
                </select>
            </div>
            <div class="field">
                <label for="application_email">Application email address</label>
                <input
                    type="email"
                    name="application_email"
                    id="application_email"
                    placeholder="Only if specified in the advert" value="<?= e($editingJob['application_email'] ?? ''); ?>"
                />
            </div>
            <div class="field full-width">
                <label for="email_subject">Required email subject</label>
                <input
                    name="email_subject"
                    id="email_subject"
                    maxlength="250"
                    placeholder="Use the exact subject requested, if any" value="<?= e($editingJob['email_subject'] ?? ''); ?>"
                />
            </div>
            <div class="field full-width">
                <label for="instructions">Application instructions</label>
                <textarea
                    name="instructions"
                    id="instructions"
                    placeholder="Required attachments, declarations or other employer instructions"
                ><?= e($editingJob['instructions'] ?? ''); ?></textarea>
            </div>
            <label class="check-label full-width">
                <input type="checkbox" name="verified" value="1" <?= !empty($editingJob['verified_at']) ? 'checked' : ''; ?> />
                I have checked the details and application instructions against the original
                advertisement.
            </label>
        </div>
        <div class="form-actions">
            <a class="btn" href="<?= e(app_base_path()); ?>/jobs">Cancel</a>
            <button class="btn btn-primary" type="submit" data-loading="Saving your opportunity…">
                <?= icon('bookmark'); ?>Save opportunity
            </button>
        </div>
    </form>
</section>

</div>
<script nonce="<?= e(CSP_NONCE); ?>">
document.addEventListener('alpine:init', () => {
    Alpine.data('jobImport', () => ({
        method: 'manual',
        busy: false,
        choose(method, focus = false) {
            this.method = method;
            if (focus) this.$nextTick(() => document.querySelector(`[data-import-tab="${method}"]`)?.focus());
        },
        moveTab(button, direction) {
            const tabs = [...document.querySelectorAll('[data-import-tab]')];
            const next = (tabs.indexOf(button) + direction + tabs.length) % tabs.length;
            this.choose(tabs[next].dataset.importTab, true);
        },
        edgeTab(last) {
            const tabs = [...document.querySelectorAll('[data-import-tab]')];
            this.choose(tabs[last ? tabs.length - 1 : 0].dataset.importTab, true);
        },
        populate(data) {
            const form = document.querySelector('#job-details-form');
            for (const [name, value] of Object.entries(data)) {
                const field = form.elements.namedItem(name);
                if (field && field.type !== 'checkbox') field.value = value ?? '';
            }
            form.elements.namedItem('verified').checked = false;
            document.querySelector('#extraction-notice').hidden = false;
            document.querySelector('#extraction-uncertainties').textContent = data.uncertainties?.length
                ? `Needs your review: ${data.uncertainties.join(' ')}`
                : 'Verify all information against the original advertisement before saving.';
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
        },
        async extract(event, poster = false) {
            if (this.busy) return;
            const form = event.currentTarget;
            form.querySelector('.form-error').hidden = true;
            this.busy = true;
            try {
                await this.$store.app.withLoading(event.submitter, async () => {
                    try {
                        let data = new FormData(form);
                        if (poster) {
                            const uploaded = await this.$store.app.request(this.$store.app.url('/api/documents'), { data });
                            document.querySelector('#job-details-form').elements.namedItem('poster_document_id').value = uploaded.document.id;
                            this.$store.app.notify('Poster stored privately. Reading the advertisement…');
                            data = { type: 'poster', document_id: uploaded.document.id };
                        }
                        const extracted = await this.$store.app.request(this.$store.app.url('/api/jobs/extract'), { data });
                        this.populate(extracted.data);
                        this.$store.app.notify(extracted.message);
                    } catch (error) {
                        this.$store.app.formError(form, error.message + (poster ? ' You can enter the details manually below.' : ''));
                    }
                });
            } finally { this.busy = false; }
        },
    }));
});
</script>
