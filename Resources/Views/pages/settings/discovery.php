<?php
$accountPreferences = (new \App\Repositories\AccountRepository())->preferences(\App\Services\Security\Auth::id());
$selected = json_decode($accountPreferences['source_keys'] ?? '[]', true) ?: [];
$available = (new \App\Services\OnboardingService())->sources('technology') + (new \App\Services\OnboardingService())->sources('other');
$sharedDiscovery = \App\Services\Discovery\CatalogueDiscovery::enabled();
?>
<section class="panel"><div class="panel-header"><div><h2>Your job search</h2><p><?= $sharedDiscovery ? 'Choose your field, preferred roles and location. Synapse collects vacancies daily at 7:00 AM East Africa Time.' : 'Choose your field, preferred roles, and the sites you’d like us to check.'; ?></p></div></div>
<form action="<?= e(app_url('/api/preferences')); ?>" method="post" x-data="asyncForm" x-on:submit.prevent="submit($event)" class="panel-body form-stack">
    <?= csrf_field(); ?><div class="form-error" role="alert" hidden></div>
    <div class="form-grid"><div class="field"><label for="field-key">Your job field</label><select id="field-key" name="field_key" x-model="field"><?php foreach (\App\Services\OnboardingService::FIELDS as $key => $definition): ?><option value="<?= e($key); ?>" <?= $accountPreferences['field_key'] === $key ? 'selected' : ''; ?>><?= e($definition[0]); ?></option><?php endforeach; ?></select></div><div class="field"><label for="preferred-location">Preferred location</label><input id="preferred-location" name="location" value="<?= e($accountPreferences['location']); ?>" required maxlength="200"></div><div class="field full-width"><label for="preferred-roles">Preferred job titles</label><input id="preferred-roles" name="role_keywords" value="<?= e($accountPreferences['role_keywords']); ?>" maxlength="500" placeholder="e.g. PHP developer, IT support"><small>Separate titles with commas. Leave blank to search across your field.</small></div></div>
    <?php if (!$sharedDiscovery): ?>
    <div><h3>Your job sites</h3><p class="setup-note">Only connected sites are available. Some sites currently cover technology roles.</p><div class="source-options"><?php foreach ($available as $key => $definition): ?><label class="choice-card" x-bind:hidden="!siteAllowed($el)" data-source-field="<?= $key === 'myjobmag' ? 'all' : 'technology'; ?>"><input type="checkbox" name="sources[]" x-bind:disabled="!siteAllowed($el)" x-effect="syncSource($el)" value="<?= e($key); ?>" <?= in_array($key, $selected, true) ? 'checked' : ''; ?>><span><strong><?= e($definition['name']); ?></strong><small><?= $key === 'myjobmag' ? 'All career fields' : 'Technology and IT'; ?></small></span></label><?php endforeach; ?></div></div>
    <?php endif; ?>
    <button class="btn btn-primary self-start" type="submit">Save my search</button>
</form></section>
<?php if (!$sharedDiscovery): ?><details class="panel mt-6" x-data="disclosure" x-on:toggle="save" data-persist-disclosure="discovery"><summary class="panel-body">Advanced discovery settings</summary><?php require BASE_PATH . '/Resources/Views/pages/settings/discovery-advanced.php'; ?></details><?php endif; ?>
