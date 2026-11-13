<div class="notice">
    <?= icon('radio'); ?>
    <div>
        <strong>Real sources. Permitted access.</strong>
        <p>
            RSS/Atom and public JSON feeds are supported. An untested source is never shown as
            active. Use manual imports for websites that restrict automated access.
        </p>
    </div>
</div>
<section class="panel">
    <div class="panel-header"><h2>Job sources</h2></div>
    <?php if (!$sources):
    empty_state(
        'radio',
        'Choose where to look',
        'Add a feed or authorised JSON API below, then test it before collection.',
    );
else:
     ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Source</th>
                    <th>Health</th>
                    <th>Last successful check</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (
    $sources
    as $source
): ?>
                <tr>
                    <td>
                        <strong><?= e($source['name']); ?></strong>
                        <p class="text-[10px] mt-1"><?= e(strtoupper($source['adapter'])); ?> · <?= $source['enabled'] && $source['health'] === 'connected' ? 'Enabled' : 'Inactive'; ?></p>
                        <?php if (
    $source['last_error']
): ?>
                        <p class="text-[10px] text-earth mt-1"><?= e($source['last_error']); ?></p>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $source['health'] === 'connected' ? 'badge-green' : 'badge-warm'; ?>"><?= e(label($source['health'])); ?></span></td>
                    <td><?= e(short_date($source['last_success_at'])); ?></td>
                    <td>
                        <div class="flex gap-2 flex-wrap">
                            <a
                                class="btn btn-sm"
                                href="/settings?tab=discovery&edit_source=<?= e($source['id']); ?>#source-form"
                            >
                                Edit
                            </a>
                            <button
                                class="btn btn-sm"
                                data-action="/api/sources/<?= e($source['id']); ?>"
                                data-payload='{"action":"test"}'
                                data-loading="Checking feed…"
                            >
                                Test
                            </button>
                            <button
                                class="btn btn-sm"
                                data-action="/api/sources/<?= e($source['id']); ?>"
                                data-payload='{"action":"toggle"}'
                            >
                                <?= $source['enabled'] ? 'Disable' : 'Enable'; ?>
                            </button>
                            <button
                                class="icon-button"
                                data-action="/api/sources/<?= e($source['id']); ?>"
                                data-payload='{"action":"delete"}'
                                data-confirm="Delete this source? Saved jobs will be retained."
                                aria-label="Delete source"
                            >
                                <?= icon('trash-2'); ?>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
endif; ?>
</section>
<?php
$editingSource = null;
foreach ($sources as $candidate) {
    if ((int) $candidate['id'] === (int) ($_GET['edit_source'] ?? 0)) {
        $editingSource = $candidate;
        break;
    }
}
?>
<section class="panel" id="source-form">
    <div class="panel-header"><h2><?= $editingSource ? 'Edit source' : 'Add a permitted source'; ?></h2></div>
    <form action="/api/sources" data-async class="panel-body form-stack">
        <div class="form-error" role="alert" hidden></div>
        <input type="hidden" name="id" value="<?= e($editingSource['id'] ?? 0); ?>" />
        <div class="form-grid">
            <?php $inputField(
    'name',
    'Source name',
    $editingSource['name'] ?? '',
); ?>
            <div class="field">
                <label for="source-adapter">Source type</label>
                <select name="adapter" id="source-adapter">
                    <option value="rss">RSS / Atom feed</option>
                    <option value="json" <?= ($editingSource[ 'adapter' ] ?? '') === 'json' ? 'selected' : ''; ?>>Public JSON / JSON Feed</option>
                </select>
            </div>
            <div class="full-width"><?php $inputField(
    'url',
    'Public HTTPS feed URL',
    $editingSource['url'] ?? '',
    'url',
); ?></div>
        </div>
        <?php $checkField(
    'permission_confirmed',
    'I have confirmed that this source permits automated collection through this feed or API.',
    (bool) ($editingSource['permission_confirmed'] ?? false),
); ?>
        <button class="btn btn-primary self-start" data-loading="Saving source…">
            <?= icon('plus'); ?>Save source
        </button>
    </form>
</section>
<section class="panel">
    <div class="panel-header"><h2>Your discovery preferences</h2></div>
    <form action="/api/settings" data-async class="panel-body">
        <input type="hidden" name="section" value="discovery" />
        <div class="form-error" role="alert" hidden></div>
        <div class="form-grid">
            <?php foreach (
    [
        'keywords' => 'Search keywords',
        'excluded_keywords' => 'Exclude keywords',
        'locations' => 'Preferred locations',
        'categories' => 'Job categories',
    ]
    as $field => $name
) {
    $inputField($field, $name, $settings[$field], 'text', 'Separate with commas.');
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
