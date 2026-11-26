<div class="page-heading">
    <div><div class="eyebrow mb-2">SYNAPSE OPERATIONS</div><h1>Administration</h1><p>Manage the catalogue and keep collection running.</p></div>
    <div class="heading-actions">
        <button class="btn" x-data="actionButton" x-on:click.prevent="run" data-action="<?= e(app_url('/api/admin/collect')); ?>" data-loading="Requesting collection…" <?= !$collectorConfigured ? 'disabled' : ''; ?>><?= icon('refresh-cw'); ?>Collect now</button>
        <a class="btn btn-primary" href="<?= e(app_url('/admin/jobs/add')); ?>"><?= icon('plus'); ?>Add catalogue job</a>
    </div>
</div>
<div class="notice mb-5"><div><strong><?= e($counts['published']); ?> published · <?= e($counts['total']); ?> total vacancies</strong><p>Daily collection time: 7:00 AM East Africa Time, once scheduling is enabled. Manual runs use the same collector and limits.</p></div></div>
<?php if (!$collectorConfigured): ?>
<div class="notice mb-5"><div><strong>Collector setup pending</strong><p>You can manage jobs and sources now. Collection becomes available when the Cloud Run job and private server credentials are configured.</p></div></div>
<?php endif; ?>
<nav class="import-tabs" aria-label="Administration sections">
    <?php foreach (['jobs' => 'Jobs', 'sources' => 'Sources', 'runs' => 'Collection runs'] as $key => $name): ?>
    <a class="import-tab <?= $tab === $key ? 'selected' : ''; ?>" href="<?= e(app_url('/admin?tab=' . $key)); ?>" <?= $tab === $key ? 'aria-current="page"' : ''; ?>><?= e($name); ?></a>
    <?php endforeach; ?>
</nav>
<?php if ($tab === 'jobs'): ?>
<section class="panel">
    <div class="toolbar"><form method="get" class="filter-form"><input type="hidden" name="tab" value="jobs" /><input name="q" type="search" placeholder="Search catalogue jobs…" aria-label="Search catalogue jobs" value="<?= e($_GET['q'] ?? ''); ?>" /><button class="btn">Search</button></form></div>
    <div class="table-wrap"><table><thead><tr><th>Opportunity</th><th>Source</th><th>Publication</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($jobs as $entry): $job = $entry['job']; ?>
    <tr>
        <td><strong><?= e($job['title']); ?></strong><div class="muted"><?= e($job['employer']); ?> · <?= e($job['location'] ?: 'Location not supplied'); ?></div></td>
        <td><?= e($entry['source_name'] ?: 'Manually added'); ?><div class="muted"><?= $entry['editor_locked'] ? 'Admin edits preserved' : 'Updates from collector'; ?></div></td>
        <td><span class="badge"><?= $entry['published'] ? 'Published' : 'Unpublished'; ?></span></td>
        <td><a class="text-link" href="<?= e(app_url('/admin/jobs/' . $entry['id'] . '/edit')); ?>">Edit</a>
            <button class="btn" x-data="actionButton" x-on:click.prevent="run" data-action="<?= e(app_url('/api/admin/jobs/' . $entry['id'] . '/publish')); ?>" data-payload="<?= e(json_encode(['published' => $entry['published'] ? 0 : 1])); ?>"><?= $entry['published'] ? 'Unpublish' : 'Publish'; ?></button>
        </td>
    </tr>
    <?php endforeach; ?>
    <?php if ($jobs === []): ?><tr><td colspan="4">No catalogue jobs yet. Add a vacancy or run collection.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>
<?php $paginationPath = '/admin?tab=jobs'; require BASE_PATH . '/Resources/Views/components/catalogue-pagination.php'; ?>
<?php elseif ($tab === 'sources'): ?>
<section class="panel mb-6">
    <div class="panel-header"><h2>Collection sources</h2></div>
    <div class="table-wrap"><table><thead><tr><th>Source</th><th>State</th><th>Last success</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($sources as $source): ?>
    <tr><td><strong><?= e($source['name']); ?></strong><div class="muted"><?= e($source['adapter']); ?> · Up to <?= e($source['max_jobs']); ?> jobs per run</div><?php if ($source['last_error']): ?><p class="muted"><?= e($source['last_error']); ?></p><?php endif; ?></td>
        <td><span class="badge"><?= $source['enabled'] ? 'Enabled' : 'Paused'; ?></span><div class="muted"><?= e(label($source['health'])); ?></div></td>
        <td><?= e($source['last_success_at'] ?: 'Never'); ?></td>
        <td><a class="text-link" href="<?= e(app_url('/admin?tab=sources&source=' . $source['id'])); ?>">Configure</a>
            <button class="btn" x-data="actionButton" x-on:click.prevent="run" data-action="<?= e(app_url('/api/admin/collect')); ?>" data-payload="<?= e(json_encode(['source_id' => (int) $source['id']])); ?>" <?= !$collectorConfigured || !$source['enabled'] ? 'disabled' : ''; ?>>Collect</button>
        </td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</section>
<?php
$source = $editingSource ?? ['id' => null, 'name' => '', 'adapter' => 'json', 'url' => '', 'config_json' => '{}', 'max_jobs' => 20, 'enabled' => 0, 'permission_confirmed' => 0, 'source_key' => ''];
$config = json_decode($source['config_json'], true);
$builtin = isset(\App\Services\Discovery\SourceCatalog::SOURCES[$source['source_key']]);
?>
<section class="panel">
    <div class="panel-header"><h2><?= $source['id'] ? 'Configure source' : 'Add a source'; ?></h2></div>
    <form class="panel-body form-grid" x-data="asyncForm" x-on:submit.prevent="submit" method="post" action="<?= e(app_url('/api/admin/sources' . ($source['id'] ? '/' . $source['id'] : ''))); ?>">
        <?= csrf_field(); ?><div class="form-error full-width" role="alert" hidden></div>
        <label>Source name<input name="name" required maxlength="200" value="<?= e($source['name']); ?>" /></label>
        <?php if ($builtin): ?><p class="muted">Built-in source: <?= e($source['adapter']); ?>. Its collection endpoint is managed by the adapter.</p>
        <?php else: ?>
        <label>Feed type<select name="adapter"><option value="json" <?= $source['adapter'] === 'json' ? 'selected' : ''; ?>>Structured JSON jobs</option><option value="rss" <?= $source['adapter'] === 'rss' ? 'selected' : ''; ?>>RSS or Atom with structured vacancy pages</option></select></label>
        <label>Public HTTPS feed URL<input type="url" name="url" required maxlength="2048" value="<?= e($source['url']); ?>" /></label>
        <p class="muted">New website layouts may need an adapter. JSON entries need an explicit title, employer and description. RSS links must lead to pages with JobPosting data.</p>
        <?php endif; ?>
        <?php if ($source['adapter'] === 'indeed_api'): ?>
        <label>Search query<input name="query" required value="<?= e($config['query'] ?? 'IT jobs in Kenya'); ?>" /></label>
        <label>Search location<input name="location" required value="<?= e($config['location'] ?? 'Kenya'); ?>" /></label>
        <p class="muted">Uses the collector’s private SerpApi key. Requests count against the provider’s quota.</p>
        <?php endif; ?>
        <?php if ($source['source_key'] === 'talent'): ?><p class="muted">Review Talent.com’s collection and reuse terms before enabling this connector.</p><?php endif; ?>
        <label>Maximum jobs per run<input name="max_jobs" type="number" min="1" max="<?= $source['adapter'] === 'myjobmag' ? 100 : 20; ?>" required value="<?= e($source['max_jobs']); ?>" /></label>
        <label class="check-label full-width"><input type="checkbox" name="permission_confirmed" value="1" <?= $source['permission_confirmed'] ? 'checked' : ''; ?> />This source permits this collection and use of its listings.</label>
        <label class="check-label full-width"><input type="checkbox" name="enabled" value="1" <?= $source['enabled'] ? 'checked' : ''; ?> />Enable collection</label>
        <div><button type="submit" class="btn btn-primary">Save source</button> <a class="text-link" href="<?= e(app_url('/admin?tab=sources')); ?>">Add another source</a></div>
    </form>
</section>
<?php else: ?>
<section class="panel"><div class="panel-header"><h2>Recent collection runs</h2><a class="text-link" href="<?= e(app_url('/admin?tab=runs')); ?>">Refresh</a></div>
<div class="table-wrap"><table><thead><tr><th>Run</th><th>State</th><th>Results</th><th>Details</th></tr></thead><tbody>
<?php foreach ($runs as $run): ?>
<tr><td><strong>#<?= e($run['id']); ?> · <?= e($run['source_name'] ?: 'All enabled sources'); ?></strong><div class="muted"><?= e($run['created_at']); ?> EAT</div><div class="muted"><?= $run['requested_by'] === null ? 'Scheduled' : 'Manual'; ?></div></td>
<td><span class="badge"><?= e(label($run['status'])); ?></span></td><td><?= e($run['inserted_count']); ?> new · <?= e($run['updated_count']); ?> refreshed</td>
<td><?= e($run['error_summary'] ?: ($run['completed_at'] ? 'Finished ' . $run['completed_at'] : 'Waiting for the collector')); ?></td></tr>
<?php endforeach; ?>
<?php if ($runs === []): ?><tr><td colspan="4">No collection runs yet.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php endif; ?>
