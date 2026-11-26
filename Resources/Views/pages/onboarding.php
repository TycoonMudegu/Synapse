<?php
$stepNames = ['Your search', 'Your documents', 'Your CV check', 'Your AI', 'Your next move'];
$selectedSources = json_decode($preferences['source_keys'] ?? '[]', true) ?: [];
$sharedDiscovery = \App\Services\Discovery\CatalogueDiscovery::enabled();
?>
<div class="setup-intro"><span class="eyebrow">LET’S MAKE THIS YOURS</span><p>Hi <?= e(explode(' ', $currentUser['name'])[0]); ?>. A few small steps, then you’re in.</p></div>
<nav class="setup-steps" aria-label="Setup progress">
    <?php foreach ($stepNames as $index => $name): ?><div class="setup-step <?= $step === $index + 1 ? 'current' : ($step > $index + 1 ? 'complete' : ''); ?>" <?= $step === $index + 1 ? 'aria-current="step"' : ''; ?>><span><?= $step > $index + 1 ? icon('check') : $index + 1; ?></span><strong><?= e($name); ?></strong></div><?php endforeach; ?>
</nav>
<section class="setup-card">
<div class="setup-card-scroll" tabindex="0" aria-label="Setup step details">
<?php if ($step === 1): ?>
    <div class="setup-card-heading"><span class="setup-symbol"><?= icon('compass'); ?></span><h1>What does your next role look like?</h1><p>Tell us what you’re looking for. We’ll help you focus on the right opportunities.</p></div>
    <form id="setup-step-form" action="<?= e(app_url('/api/onboarding')); ?>" method="post" x-data="asyncForm" x-on:submit.prevent="submit($event)" class="form-stack">
        <?= csrf_field(); ?><input type="hidden" name="stage" value="preferences"><div class="form-error" role="alert" hidden></div>
        <div class="setup-preferences-grid"><div class="setup-preference-fields"><div class="form-grid"><div class="field"><label for="field-key">Your job field</label><select id="field-key" name="field_key" x-model="field"><?php foreach ($fields as $key => $definition): ?><option value="<?= e($key); ?>" <?= $preferences['field_key'] === $key ? 'selected' : ''; ?>><?= e($definition[0]); ?></option><?php endforeach; ?></select></div><div class="field"><label for="location">Where would you like to work?</label><input id="location" name="location" value="<?= e($preferences['location']); ?>" placeholder="Kenya, Nairobi, remote…" maxlength="200" required></div></div>
        <div class="field"><label for="role-keywords">Preferred job titles <span class="optional">(optional)</span></label><input id="role-keywords" name="role_keywords" value="<?= e($preferences['role_keywords']); ?>" placeholder="e.g. PHP developer, IT support" maxlength="500"><small>Separate titles with commas. You can refine this later.</small></div>
        </div><div class="setup-source-choices"><?php if ($sharedDiscovery): ?><h2 class="setup-section-title">Fresh jobs, collected for you</h2><p class="setup-note">Synapse collects vacancies daily at 7:00 AM East Africa Time. Your field, preferred roles and location help us find relevant opportunities.</p><?php else: ?><h2 class="setup-section-title">Where should we look?</h2><p class="setup-note">These are the sites currently connected for your field. You can choose more than one.</p>
        <div class="source-options"><?php foreach ($sources as $key => $source): ?><label class="choice-card" x-bind:hidden="!siteAllowed($el)" data-source-field="<?= $key === 'myjobmag' ? 'all' : 'technology'; ?>"><input type="checkbox" name="sources[]" x-bind:disabled="!siteAllowed($el)" x-effect="syncSource($el)" value="<?= e($key); ?>" <?= in_array($key, $selectedSources, true) ? 'checked' : ''; ?>><span><strong><?= e($source['name']); ?></strong><small><?= $key === 'myjobmag' ? 'Jobs across career fields' : 'Technology and IT vacancies'; ?></small></span><?= icon('briefcase-business'); ?></label><?php endforeach; ?></div>
        <?php if (!$sources): ?><p class="soft-note">Job sites are being connected. You can continue and add jobs yourself.</p><?php endif; ?><?php endif; ?></div></div>
        
    </form>
<?php elseif ($step === 2): ?>
    <div class="setup-card-heading"><span class="setup-symbol"><?= icon('file-user'); ?></span><h1>Bring your experience with you.</h1><p>Upload your CV for a free first check. A cover letter template is welcome, too.</p></div>
    <form id="setup-step-form" action="<?= e(app_url('/api/onboarding')); ?>" method="post" enctype="multipart/form-data" x-data="asyncForm" x-on:submit.prevent="submit($event)" class="form-stack">
        <?= csrf_field(); ?><input type="hidden" name="stage" value="documents"><div class="form-error" role="alert" hidden></div>
        <div class="upload-zone setup-upload"><?= icon('cloud-upload'); ?><h2>Your CV</h2><p>PDF or DOCX, up to <?= e(\App\Core\Env::get('UPLOAD_MAX_MB', '10')); ?> MB. Choose a document with selectable text.</p><input id="cv-upload" name="cv" type="file" accept=".pdf,.docx" aria-label="Upload your CV"><?php if ($preferences['cv_document_id']): ?><small>Your CV is already saved. Choose a file to replace it.</small><?php endif; ?></div>
        <div class="field"><label for="letter-upload">Cover letter template <span class="optional">(optional)</span></label><input id="letter-upload" name="letter" type="file" accept=".pdf,.docx"><small>PDF or DOCX. Keep your own starting point for future applications.</small></div>
        <div class="coming-soon" aria-disabled="true"><?= icon('sparkles'); ?><div><strong>Don’t have a CV or cover letter?</strong><p>Answer a few questions and create one with us.</p></div><button class="btn btn-small" type="button" disabled>Coming soon</button></div>
        <p class="setup-note"><?= icon('lock-keyhole'); ?>Your files are stored privately and are only available in your account.</p>
        
    </form>
<?php elseif ($step === 3): ?>
    <div class="setup-card-heading"><span class="setup-symbol"><?= icon('file-check'); ?></span><h1>A fresh look at your CV.</h1><p>Your first check is on us. See how your CV is shaping up for your chosen field.</p></div>
    <?php if ($review): require BASE_PATH . '/Resources/Views/components/cv-score.php'; elseif ($cvProcessing): ?>
        <div class="setup-empty"><?= icon('file-search'); ?><h2>We’re reading your CV.</h2><p>Your upload is saved. Your check will appear here when processing finishes. You can continue setting up your account.</p></div>
    <?php else: ?><div class="setup-empty"><?= icon('file-user'); ?><h2>Your CV check can wait.</h2><p>Upload a CV when you’re ready. You can keep looking for jobs in the meantime.</p><a class="text-link" href="<?= e(app_url('/onboarding?step=2')); ?>">Upload my CV</a></div><?php endif; ?>
    <?php if (!$membership['active']): require BASE_PATH . '/Resources/Views/components/upgrade.php'; else: ?><p class="soft-note">Plus is active. Open My CV after setup to get personalised improvements.</p><?php endif; ?>
    <form id="setup-step-form" action="<?= e(app_url('/api/onboarding')); ?>" method="post" x-data="asyncForm" x-on:submit.prevent="submit($event)"><?= csrf_field(); ?><input type="hidden" name="stage" value="review"></form>
<?php elseif ($step === 4): ?>
    <div class="setup-card-heading"><span class="setup-symbol"><?= icon('sparkles'); ?></span><h1>A little help from AI?</h1><p>Bring your own provider, or let Synapse Plus take care of it. You can also decide later.</p></div>
    <?php if ($membership['active']): ?><div class="notice"><?= icon('circle-check'); ?><div><strong>Built-in AI is included with Plus.</strong><p>You can continue without adding a key. Your own provider is optional.</p></div></div><?php endif; ?>
    <details class="provider-details" <?= $provider ? 'open' : ''; ?>><summary>I have my own AI API key</summary>
    <form id="setup-provider-form" action="<?= e(app_url('/api/onboarding')); ?>" method="post" x-data="asyncForm" x-on:submit.prevent="submit($event)" class="form-stack">
        <?= csrf_field(); ?><input type="hidden" name="stage" value="provider"><div class="form-error" role="alert" hidden></div>
        <div class="field"><label for="provider">AI provider</label><select name="provider" id="provider" x-model="provider"><option value="gemini">Google Gemini</option><option value="compatible">Another compatible provider</option></select></div>
        <div class="field" x-bind:hidden="provider !== 'compatible'" hidden><label for="endpoint">API base URL</label><input id="endpoint" name="endpoint" x-bind:required="provider === 'compatible'" type="url" placeholder="https://your-provider.example/v1"></div>
        <div class="field"><label for="api-key">Your API key</label><input id="api-key" name="api_key" type="password" autocomplete="off" required><small>Encrypted before storage. We never display it again.</small></div>
        <div class="field"><label for="model">Model name</label><input id="model" name="model" placeholder="The model enabled in your provider account" maxlength="200" required></div>
        <div><h3>Provider usage budget</h3><p class="setup-note">Enter the model’s current USD price per million tokens so we can enforce a spending limit. Your provider bills your own key separately.</p><div class="form-grid"><div class="field"><label for="input-price">Input price (USD / million tokens)</label><input id="input-price" name="input_per_million" type="number" step="0.001" min="0.001" required></div><div class="field"><label for="output-price">Output price (USD / million tokens)</label><input id="output-price" name="output_per_million" type="number" step="0.001" min="0.001" required></div></div></div>
        <button class="btn btn-primary" type="submit">Save my AI key</button>
    </form></details>
    <?php if (!$membership['active']): require BASE_PATH . '/Resources/Views/components/upgrade.php'; endif; ?>
    <form id="setup-step-form" action="<?= e(app_url('/api/onboarding')); ?>" method="post" x-data="asyncForm" x-on:submit.prevent="submit($event)"><?= csrf_field(); ?><input type="hidden" name="stage" value="provider"><input type="hidden" name="skip" value="1"></form>
<?php else: ?>
    <div class="setup-card-heading"><span class="setup-symbol"><?= icon('send'); ?></span><h1>Your search. Your pace.</h1><p>Apply yourself, or give your job search a helping hand with automation.</p></div>
    <div class="setup-benefits"><div><?= icon('compass'); ?><span><strong>Find jobs for free</strong><small>Search, filter, save and track the roles you care about.</small></span></div><div><?= icon('file-check'); ?><span><strong>Prepare a thoughtful application</strong><small>Plus can match jobs to your experience and help tailor your documents.</small></span></div><div><?= icon('send'); ?><span><strong>Let approved email applications move</strong><small>Connect Gmail and verify your profile, job instructions and attachments first. Website forms stay with you.</small></span></div></div>
    <?php if (!$membership['active']): require BASE_PATH . '/Resources/Views/components/upgrade.php'; endif; ?>
    <form id="setup-step-form" action="<?= e(app_url('/api/onboarding')); ?>" method="post" x-data="asyncForm" x-on:submit.prevent="submit($event)" class="form-stack">
        <?= csrf_field(); ?><input type="hidden" name="stage" value="automation"><div class="form-error" role="alert" hidden></div>
        <?php if ($membership['active']): ?><label class="choice-card"><input type="checkbox" name="automatic" value="1"><span><strong>Enable automatic email applications</strong><small>Uses verified documents, an eligible job match, and a daily limit of 5.</small></span></label><label class="check-label"><input type="checkbox" name="confirm_automatic" value="1">I authorise Synapse to send approved applications through my connected Gmail when the checks above pass.</label><?php else: ?><p class="setup-note">Automatic applications are a Plus feature. You can start using the free workspace now.</p><?php endif; ?>
        
    </form>
<?php endif; ?>
</div>
<div class="setup-card-footer">
    <?php if ($step > 1): ?><a class="text-link" href="<?= e(app_url('/onboarding?step=' . ($step - 1))); ?>"><?= icon('arrow-left'); ?>Back</a><?php else: ?><span class="setup-note">Free to browse. No card needed.</span><?php endif; ?>
    <button class="btn btn-primary" type="submit" form="setup-step-form" data-loading="<?= $step === 2 ? 'Reading your documents…' : 'Continuing…'; ?>"><?= $step === 2 ? 'Save and continue' : ($step === 5 ? 'Open my workspace' : ($step === 4 ? ($membership['active'] ? 'Continue with built-in AI' : 'Skip for now') : 'Continue')); ?><?= icon('arrow-right'); ?></button>
</div>
</section>
<div class="setup-skip"><form action="<?= e(app_url('/api/onboarding/skip')); ?>" method="post" x-data="asyncForm" x-on:submit.prevent="submit($event)"><?= csrf_field(); ?><button class="text-link" type="submit">Finish setup later</button></form><span>You can change these choices in Settings.</span></div>
