<?php $job = $entry['job']; ?>
<div class="page-heading"><div><div class="eyebrow mb-2"><?= e($entry['source_name'] ?: 'SYNAPSE CATALOGUE'); ?></div><h1><?= e($job['title']); ?></h1><p><?= e($job['employer']); ?> · <?= e($job['location'] ?: 'Location not supplied'); ?></p></div>
<button class="btn btn-primary" x-data="actionButton" x-on:click.prevent="run" data-action="<?= e(app_url('/api/catalogue/' . $entry['id'] . '/save')); ?>">Save to my jobs</button></div>
<section class="panel"><div class="panel-header"><h2>Vacancy details</h2><a class="text-link" href="<?= e(app_url('/catalogue')); ?>">Back to catalogue</a></div><div class="panel-body">
<p>Deadline: <?= e(short_date($job['deadline'] ?? null)); ?></p>
<?php if (!empty($job['original_url'])): ?><p><a class="text-link" href="<?= e($job['original_url']); ?>" target="_blank" rel="noopener noreferrer">View original vacancy <?= icon('external-link'); ?></a></p><?php endif; ?>
<?php foreach (['description' => 'Description', 'requirements' => 'Requirements', 'qualifications' => 'Qualifications', 'responsibilities' => 'Responsibilities', 'instructions' => 'Application instructions'] as $field => $label): if (!empty($job[$field])): ?>
<h3 class="mt-5"><?= e($label); ?></h3><p class="whitespace-pre-wrap"><?= e($job[$field]); ?></p>
<?php endif; endforeach; ?>
</div></section>
