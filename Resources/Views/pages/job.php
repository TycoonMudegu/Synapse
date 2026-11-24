<div class="page-heading">
    <div>
        <a href="<?= e(app_base_path()); ?>/jobs" class="text-link mb-2"><?= icon('arrow-left'); ?>Back to my jobs</a>
        <h1>One possibility. A closer look.</h1>
    </div>
    <div class="heading-actions"><a class="btn" href="<?= e(app_base_path()); ?>/jobs/<?= e($job['id']); ?>/edit"><?= icon('square-pen'); ?>Edit details</a>
        <button
            class="btn"
            x-data="actionButton" x-on:click.prevent="run" data-action="<?= e(app_base_path()); ?>/api/jobs/<?= e($job['id']); ?>/analyse"
            data-loading="Queuing analysis…"
        >
            <?= icon('sparkles'); ?>Analyse fit
        </button>
        <button class="btn btn-primary" x-data="actionButton" x-on:click.prevent="run" data-action="<?= e(app_base_path()); ?>/api/jobs/<?= e($job['id']); ?>/apply">
            <?= icon('send'); ?>Prepare application
        </button>
    </div>
</div>
<div class="content-grid">
    <div class="stack">
        <section class="panel">
            <div class="panel-body">
                <div class="flex items-center justify-between gap-3">
                    <span class="badge"><?= e(label($job['status'])); ?></span>
                    <span class="muted text-[10px]">Saved <?= e(short_date($job['created_at'])); ?></span>
                </div>
                <h2 class="detail-heading"><?= e($job['title']); ?></h2>
                <p class="text-earth"><?= e($job['employer']); ?></p>
                <div class="detail-meta">
                    <span><?= icon('map-pin'); ?><?= e($job['location'] ?: 'Location not supplied'); ?></span>
                    <span><?= icon('briefcase-business'); ?><?= e(label($job['employment_type'])); ?></span>
                    <span><?= icon('monitor'); ?><?= e(label($job['workplace'])); ?></span>
                </div>
                <?php foreach (
                [
                    'description' => 'About the role',
                    'requirements' => 'Requirements',
                    'responsibilities' => 'Responsibilities',
                    'qualifications' => 'Qualifications',
                    'instructions' => 'How to apply',
                ]
                as $field => $name
            ):
                if (!$job[$field]) {
                    continue;
                } ?>
                <div class="detail-section">
                    <h3><?= e($name); ?></h3>
                    <div class="prose-text"><?= e($job[$field]); ?></div>
                </div>
                <?php
            endforeach; ?>
            </div>
        </section>
        <section class="panel">
            <div class="panel-header">
                <h2>A little AI assistance</h2>
                <span class="badge">Verify every draft</span>
            </div>
            <form
                class="panel-body form-stack"
                x-data="aiAssist" x-on:submit.prevent="submit($event)"
                action="<?= e(app_base_path()); ?>/api/jobs/<?= e($job['id']); ?>/assist"
            >
                <div class="form-error" role="alert" hidden></div>
                <div class="field">
                    <label for="assist-task">What would help?</label>
                    <select name="task" id="assist-task">
                        <option value="summary">Summarise this advertisement</option>
                        <option value="questions">Draft application question answers</option>
                    </select>
                </div>
                <div class="field">
                    <label for="questions">Application questions, if relevant</label>
                    <textarea
                        name="questions"
                        id="questions"
                        placeholder="Paste questions from the employer's application form…"
                    ></textarea>
                </div>
                <button class="btn btn-soft self-start" data-loading="Preparing a factual draft…">
                    <?= icon('sparkles'); ?>Prepare draft
                </button>
                <div x-text="result" x-bind:hidden="!result" class="prose-text" role="status" hidden></div>
            </form>
        </section>
    </div>
    <aside class="stack">
        <section class="panel">
            <div class="panel-header"><h2>Your fit, thoughtfully considered</h2></div>
            <div class="panel-body pt-0">
                <?php if ($job['result_json']):
                $match = json_decode($job['result_json'], true); ?>
                <div class="text-[43px] text-brand font-extrabold">
                    <?= e($job['score']); ?>
                    <span class="text-xl">%</span>
                </div>
                <span class="badge <?= $job[ 'eligibility' ] === 'eligible' ? 'badge-green' : 'badge-warm'; ?>"><?= e(label($job['eligibility'])); ?></span>
                <p class="muted text-xs mt-4"><?= e($match['explanation']); ?></p>
                <?php foreach (
                    [
                        'matched_requirements' => 'Matched requirements',
                        'missing_requirements' => 'Missing requirements',
                        'strengths' => 'Strengths',
                        'concerns' => 'Things to consider',
                    ]
                    as $field => $name
                ):
                    if (!$match[$field]) {
                        continue;
                    } ?>
                <h3 class="mt-5 mb-2 text-xs"><?= e($name); ?></h3>
                <ul class="list-disc pl-4 text-[11px] muted space-y-2">
                    <?php foreach (
    $match[$field]
    as $item
): ?>
                    <li><?= e($item); ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php
                endforeach; ?>
                <h3 class="mt-5 mb-2 text-xs">Mandatory eligibility</h3>
                <?php foreach (
                    $match['mandatory_checks']
                    as $check
                ): ?>
                <div class="soft-note mb-2">
                    <strong><?= e($check['requirement']); ?></strong>
                    <div class="mt-1"><?= e(label($check['status'])); ?> · <?= e($check['evidence']); ?></div>
                </div>
                <?php endforeach; ?>
                <p class="text-xs text-brand mt-4"><?= e($match['recommended_action']); ?></p>
                <?php
            else:
                 ?>
                <div class="empty-icon"><?= icon('scan-search'); ?></div>
                <h3 class="text-xs">There's more to a role than a percentage.</h3>
                <p class="muted text-xs mt-2">
                    Analyse this opportunity against your verified career profile to see the fit,
                    the gaps and any mandatory requirements.
                </p>
                <?php
            endif; ?>
            </div>
        </section>
        <section class="panel">
            <div class="panel-header"><h2>The essentials</h2></div>
            <div class="automation-summary"><div class="definition-row"><span>Source</span><strong><?= e($job['source_name'] ?: 'Manually added'); ?></strong></div>
                <div class="definition-row">
                    <span>Deadline</span>
                    <strong><?= e(short_date($job['deadline'])); ?></strong>
                </div>
                <div class="definition-row">
                    <span>Salary</span>
                    <strong><?= $job[ 'salary_min' ] !== null || $job['salary_max'] !== null ? e(($job['currency'] ?? '') . ' ' . ($job['salary_min'] ?? 'Up to') . ($job['salary_max'] !== null ? ' – ' . $job['salary_max'] : '')) : 'Not supplied'; ?></strong>
                </div>
                <div class="definition-row">
                    <span>Application</span>
                    <strong><?= e(label($job['application_method'])); ?></strong>
                </div>
                <?php
if ($job['application_email']): ?>
                <p class="text-xs text-brand break-all mb-3"><?= e($job['application_email']); ?></p>
                <?php endif;
if ($job['original_url']): ?>
                <a
                    class="text-link mb-4"
                    href="<?= e($job['original_url']); ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    Original advertisement<?= icon('external-link'); ?>
                </a>
                <?php endif;
?> <?php if (!$job['verified_at']): ?>
                <form action="<?= e(app_base_path()); ?>/api/jobs/<?= e($job['id']); ?>/verify" x-data="asyncForm" x-on:submit.prevent="submit($event)" class="form-stack">
                    <div class="form-error" role="alert" hidden></div>
                    <label class="check-label">
                        <input type="checkbox" name="verified" value="1" required />
                        I have checked the details and application instructions against the
                        original.
                    </label>
                    <button class="btn btn-soft">Verify details</button>
                </form>
                <?php else: ?>
                <span class="badge badge-green"><?= icon('shield-check', 'w-3 h-3'); ?>Details verified</span>
                <?php endif; ?>
            </div>
        </section>
        <form
            action="<?= e(app_base_path()); ?>/api/jobs/<?= e($job['id']); ?>/status"
            x-data="asyncForm" x-on:submit.prevent="submit($event)"
            class="panel panel-body form-stack"
        >
            <div class="form-error" hidden role="alert"></div>
            <div class="field">
                <label for="job-status">Keep things organised</label>
                <select name="status" id="job-status">
                    <?php foreach (
    ['saved', 'shortlisted', 'archived']
    as $status
): ?>
                    <option value="<?= e($status); ?>" <?= $job['status'] === $status ? 'selected' : ''; ?>><?= e(label($status)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn">Update job</button>
        </form>
    </aside>
</div>
<script nonce="<?= e(CSP_NONCE); ?>">
document.addEventListener('alpine:init', () => {
    Alpine.data('aiAssist', () => ({
        busy: false,
        result: '',
        async submit(event) {
            if (this.busy) return;
            const form = event.currentTarget;
            form.querySelector('.form-error').hidden = true;
            this.busy = true;
            try {
                await this.$store.app.withLoading(event.submitter, async () => {
                    try {
                        const response = await this.$store.app.request(form.action, { data: new FormData(form) });
                        this.result = response.data.summary ?? response.data.answers?.join('\n\n') ?? '';
                        if (response.data.uncertainties?.length) this.result += `\n\nNeeds your review:\n${response.data.uncertainties.join('\n')}`;
                        this.$store.app.notify(response.message);
                    } catch (error) { this.$store.app.formError(form, error.message); }
                });
            } finally { this.busy = false; }
        },
    }));
});
</script>
