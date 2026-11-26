<section class="panel">
    <div class="panel-header"><h2>Your discovery preferences</h2></div>
    <form action="<?= e(app_base_path()); ?>/api/settings" x-data="asyncForm" x-on:submit.prevent="submit($event)" class="panel-body">
        <input type="hidden" name="section" value="discovery" />
        <div class="form-error" role="alert" hidden></div>
        <div class="form-grid">
            <div class="field full-width">
                <label for="discovery-role-focus">Role focus</label>
                <select name="role_focus" id="discovery-role-focus">
                    <option value="any">All job families</option>
                    <option value="technology" <?= ($settings['role_focus'] ?? 'any') === 'technology' ? 'selected' : ''; ?>>Technology &amp; IT</option>
                </select>
                <p class="muted text-xs">Technology &amp; IT includes software development, PHP/MySQL, frontend, backend, systems administration, networking, cloud, DevOps, cybersecurity and technical support. Generic titles need technical duties.</p>
            </div>
            <?php foreach (
    [
        'keywords' => 'Search keywords',
        'excluded_keywords' => 'Exclude keywords',
        'locations' => 'Preferred locations',
        'categories' => 'Job categories',
    ]
    as $field => $name
) {
    $inputField($field, $name, $settings[$field], 'text', $field === 'keywords'
        ? 'Optional: narrow your role focus. Matches any comma-separated keyword as a whole word or phrase.'
        : 'Separate with commas.');
} ?> <?php foreach (
    [
        'sources_per_run' => 'Sources checked per run',
        'jobs_per_run' => 'Jobs processed per run',
        'daily_limit' => 'Daily discovery limit',
        'interval_minutes' => 'Search interval (minutes)',
        'minimum_salary' => 'Minimum advertised salary',
        'minimum_match' => 'Minimum match for preparation (%)',
        'run_budget_usd' => 'Maximum AI cost per run (USD)',
    ]
    as $field => $name
) {
    if (($sharedDiscovery ?? false) && in_array($field, ['sources_per_run', 'run_budget_usd'], true)) {
        continue;
    }
    $inputField($field, $name, $settings[$field], 'number');
} ?>
            <div class="field">
                <label for="discovery-remote">Work arrangement</label>
                <select name="remote" id="discovery-remote">
                    <?php foreach (
    ['any', 'remote', 'hybrid', 'office']
    as $type
): ?>
                    <option value="<?= e($type); ?>" <?= $settings['remote'] === $type ? 'selected' : ''; ?>><?= e(label($type)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="full-width form-stack">
                <?php $checkField(
    'ignore_expired',
    'Ignore expired advertisements during collection.',
    $settings['ignore_expired'],
); ?>
                <p class="muted text-xs">
                    Duplicates are always prevented by URL and vacancy fingerprint.
                </p>
                <input type="hidden" name="hide_duplicates" value="1" />
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary">Save discovery settings</button>
        </div>
    </form>
</section>
