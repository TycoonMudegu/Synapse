<?php
$gmailConnected = (bool) $connection;
require BASE_PATH . '/Resources/Views/components/source-catalog.php';
$customSources = array_filter($sources, static fn ($source) => !$source['preset_key']);
?>
<section class="panel" id="google-jobs">
    <div class="panel-header"><h2>Google Jobs connection</h2></div>
    <form action="<?= e(app_url('/api/settings')); ?>" x-data="asyncForm" x-on:submit.prevent="submit($event)" class="panel-body form-stack">
        <div class="form-error" role="alert" hidden></div>
        <input type="hidden" name="section" value="google_jobs" />
        <p>Google Jobs and Indeed via Google Jobs share this SerpApi connection. Add your own key, then add either source above and choose Check API. Each source collection uses at most two search requests against your account's quota. Indeed collection keeps only vacancies with an explicit Indeed job link.</p>
        <p><?= $googleJobs['configured'] ? 'An encrypted API key is saved. Leave the key blank to keep it.' : 'No API key is configured. Google Jobs and Indeed collection is inactive.'; ?></p>
        <div class="form-grid">
            <div class="field">
                <label for="google-jobs-key">SerpApi key</label>
                <input id="google-jobs-key" type="password" name="api_key" autocomplete="new-password" value="" />
            </div>
            <div class="field">
                <label for="google-jobs-query">Google Jobs search query</label>
                <input id="google-jobs-query" name="query" maxlength="500" required value="<?= e($googleJobs['query']); ?>" />
            </div>
            <div class="field">
                <label for="google-jobs-location">Google Jobs search location</label>
                <input id="google-jobs-location" name="location" maxlength="200" required value="<?= e($googleJobs['location']); ?>" />
            </div>
        </div>
        <?php if ($googleJobs['configured']): ?>
            <label class="check-label"><input type="checkbox" name="remove_key" value="1" />Remove the saved SerpApi key and disable Google Jobs and Indeed collection</label>
        <?php endif; ?>
        <div class="heading-actions">
            <button type="submit" class="btn btn-primary">Save Google Jobs settings</button>
            <a class="text-link" href="https://serpapi.com/google-jobs-api" target="_blank" rel="noopener noreferrer">SerpApi documentation<?= icon('external-link'); ?></a>
        </div>
        <p>Changing this connection disables both sources until each passes a new check. Saved jobs are retained. Keys are encrypted and never displayed.</p>
    </form>
</section>
<section class="panel">
    <div class="panel-header"><h2>Additional feeds & APIs</h2></div>
    <?php if (!$customSources):
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
    $customSources
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
                                href="<?= e(app_base_path()); ?>/settings?tab=discovery&edit_source=<?= e($source['id']); ?>#source-form"
                            >
                                Edit
                            </a>
                            <button
                                class="btn btn-sm"
                                x-data="actionButton" x-on:click.prevent="run" data-action="<?= e(app_base_path()); ?>/api/sources/<?= e($source['id']); ?>"
                                data-payload='{"action":"test"}'
                                data-loading="Checking feed…"
                            >
                                Test
                            </button>
                            <button
                                class="btn btn-sm"
                                x-data="actionButton" x-on:click.prevent="run" data-action="<?= e(app_base_path()); ?>/api/sources/<?= e($source['id']); ?>"
                                data-payload='{"action":"toggle"}'
                            >
                                <?= $source['enabled'] ? 'Disable' : 'Enable'; ?>
                            </button>
                            <button
                                class="icon-button"
                                x-data="actionButton" x-on:click.prevent="run" data-action="<?= e(app_base_path()); ?>/api/sources/<?= e($source['id']); ?>"
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
    <form action="<?= e(app_base_path()); ?>/api/sources" x-data="asyncForm" x-on:submit.prevent="submit($event)" class="panel-body form-stack">
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
