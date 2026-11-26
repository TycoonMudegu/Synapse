<div class="page-heading"><div><div class="eyebrow mb-2">OPPORTUNITIES, COLLECTED FOR YOU</div><h1>Job catalogue</h1><p>Browse shared vacancies and save the ones you want to pursue.</p></div></div>
<section class="panel">
<div class="toolbar"><form method="get" class="filter-form"><input name="q" type="search" maxlength="150" placeholder="Search titles, employers or locations…" aria-label="Search job catalogue" value="<?= e($_GET['q'] ?? ''); ?>" /><button class="btn">Search</button></form></div>
<div class="table-wrap"><table><thead><tr><th>Opportunity</th><th>Location</th><th>Deadline</th><th></th></tr></thead><tbody>
<?php foreach ($jobs as $entry): $job = $entry['job']; ?>
<tr><td><a href="<?= e(app_url('/catalogue/' . $entry['id'])); ?>"><strong><?= e($job['title']); ?></strong></a><div class="muted"><?= e($job['employer']); ?></div><span class="badge"><?= e($entry['source_name'] ?: 'Synapse'); ?></span></td>
<td><?= e($job['location'] ?: 'Not supplied'); ?></td><td><?= e(short_date($job['deadline'] ?? null)); ?></td>
<td><button class="btn" x-data="actionButton" x-on:click.prevent="run" data-action="<?= e(app_url('/api/catalogue/' . $entry['id'] . '/save')); ?>">Save to my jobs</button></td></tr>
<?php endforeach; ?>
<?php if ($jobs === []): ?><tr><td colspan="4">No published vacancies match this view yet.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php $paginationPath = '/catalogue?'; require BASE_PATH . '/Resources/Views/components/catalogue-pagination.php'; ?>
