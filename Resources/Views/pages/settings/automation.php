<div class="notice warm">
    <?= icon('shield-check'); ?>
    <div>
        <strong>You set the pace. You stay in control.</strong>
        <p>
            Automatic submission requires an eligible match, verified job details, reviewed
            documents, a supported method and an unused submission record.
        </p>
    </div>
</div>
<section class="panel">
    <div class="panel-header"><h2>Application automation</h2></div>
    <form action="<?= e(app_base_path()); ?>/api/settings" data-async class="panel-body form-stack">
        <input type="hidden" name="section" value="automation" />
        <div class="form-error" hidden role="alert"></div>
        <div class="field">
            <label for="automation-mode">Submission mode</label>
            <select name="mode" id="automation-mode">
                <?php foreach (
    [
        'manual' => 'Manual — prepare, never send',
        'approval' => 'Approval — send after explicit approval',
        'automatic' => 'Automatic — send reviewed, eligible packages through permitted channels',
    ]
    as $mode => $name
): ?>
                <option value="<?= e($mode); ?>" <?= $settings['mode'] === $mode ? 'selected' : ''; ?>><?= e($name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php $checkField(
    'confirm_automatic',
    'I explicitly enable Automatic mode and authorise submission of reviewed, verified applications that meet these rules.',
    false,
); ?>
        <div class="form-grid"><?php
$inputField(
    'minimum_match',
    'Minimum match for Automatic mode (%)',
    $settings['minimum_match'],
    'number',
);
$inputField('daily_limit', 'Maximum applications per day', $settings['daily_limit'], 'number');
?></div>
        <?php $checkField(
    'allow_email',
    'Allow email submissions through my connected Gmail account.',
    in_array('email', $settings['permitted_methods'], true),
); ?>
        <hr class="border-[#eceee7]" />
        <?php
$checkField('discovery_enabled', 'Run scheduled job discovery.', $settings['discovery_enabled']);
$checkField(
    'analysis_enabled',
    'Automatically queue AI analysis for new opportunities.',
    $settings['analysis_enabled'],
);
$checkField(
    'preparation_enabled',
    'Prepare applications for eligible matches above my discovery match threshold.',
    $settings['preparation_enabled'],
);
$checkField('paused', 'Pause all background automation.', $settings['paused']);
?>
        <div class="soft-note">
            The CLI worker and cron scheduler must be running. Changing these settings does not
            start a server process. New AI-generated documents always need factual review; changing
            the profile invalidates approvals.
        </div>
        <div class="form-actions">
            <button class="btn btn-primary">Save automation settings</button>
        </div>
    </form>
</section>
