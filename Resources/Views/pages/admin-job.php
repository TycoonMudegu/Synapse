<?php $job = $entry['job'] ?? []; ?>
<div class="page-heading"><div><div class="eyebrow mb-2">SHARED JOB CATALOGUE</div><h1><?= e($title); ?></h1><p>Published vacancies are visible to every signed-in user. Keep the advertised facts and original application link.</p></div><a class="btn" href="<?= e(app_url('/admin')); ?>">Back to administration</a></div>
<section class="panel">
<form class="panel-body form-grid" x-data="asyncForm" x-on:submit.prevent="submit" method="post" action="<?= e(app_url('/api/admin/jobs' . ($entry ? '/' . $entry['id'] : ''))); ?>">
<?= csrf_field(); ?><div class="form-error full-width" hidden role="alert"></div>
<?php if ($entry): ?><input type="hidden" name="revision" value="<?= e($entry['revision']); ?>" /><?php endif; ?>
<?php foreach (['title' => 'Job title', 'employer' => 'Employer', 'location' => 'Location', 'industry' => 'Industry', 'original_url' => 'Original vacancy URL', 'application_email' => 'Application email', 'email_subject' => 'Required email subject'] as $field => $label): ?>
<label><?= e($label); ?><input name="<?= e($field); ?>" type="<?= $field === 'original_url' ? 'url' : ($field === 'application_email' ? 'email' : 'text'); ?>" value="<?= e($job[$field] ?? ''); ?>" <?= in_array($field, ['title', 'employer'], true) ? 'required' : ''; ?> /></label>
<?php endforeach; ?>
<label class="full-width">Description<textarea name="description" rows="10" maxlength="60000" required><?= e($job['description'] ?? ''); ?></textarea></label>
<?php foreach (['requirements' => 'Requirements', 'qualifications' => 'Qualifications', 'responsibilities' => 'Responsibilities', 'instructions' => 'Application instructions'] as $field => $label): ?>
<label><?= e($label); ?><textarea name="<?= e($field); ?>" rows="3"><?= e($job[$field] ?? ''); ?></textarea></label>
<?php endforeach; ?>
<?php foreach (['application_method' => ['manual', 'external', 'email'], 'employment_type' => ['not_specified', 'full_time', 'part_time', 'contract', 'internship', 'temporary', 'volunteer'], 'workplace' => ['not_specified', 'remote', 'hybrid', 'office']] as $field => $options): ?>
<label><?= e(label($field)); ?><select name="<?= e($field); ?>"><?php foreach ($options as $option): ?><option value="<?= e($option); ?>" <?= ($job[$field] ?? $options[0]) === $option ? 'selected' : ''; ?>><?= e(label($option)); ?></option><?php endforeach; ?></select></label>
<?php endforeach; ?>
<label>Deadline<input type="date" name="deadline" value="<?= e($job['deadline'] ?? ''); ?>" /></label>
<label>Minimum salary<input type="number" name="salary_min" min="0" step="0.01" value="<?= e($job['salary_min'] ?? ''); ?>" /></label>
<label>Maximum salary<input type="number" name="salary_max" min="0" step="0.01" value="<?= e($job['salary_max'] ?? ''); ?>" /></label>
<label>Salary currency<input name="currency" maxlength="10" value="<?= e($job['currency'] ?? ''); ?>" /></label>
<label class="check-label full-width"><input type="checkbox" name="published" value="1" <?= !$entry || $entry['published'] ? 'checked' : ''; ?> />Publish in the job catalogue</label>
<p class="muted">Saving an admin edit preserves it during future collection. Users review application instructions in their own workspaces.</p>
<div><button type="submit" class="btn btn-primary">Save catalogue job</button></div>
</form></section>
