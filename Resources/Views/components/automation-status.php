<section class="panel" aria-label="Application automation status">
    <div class="panel-header"><h2>Application progress</h2><span class="badge <?= $automationStatus['blockers'] ? '' : 'badge-green'; ?>"><?= $automationStatus['blockers'] ? 'Needs attention' : 'Ready to match'; ?></span></div>
    <div class="panel-body">
        <?php if ($automationStatus['blockers']): ?>
            <p class="setup-note">These steps are holding up matching, preparation or sending:</p>
            <ul><?php foreach ($automationStatus['blockers'] as $blocker): ?><li><a class="text-link" href="<?= e(app_url($blocker['href'])); ?>"><?= e($blocker['message']); ?></a></li><?php endforeach; ?></ul>
        <?php else: ?>
            <p>Matching and application preparation are enabled. Prepared documents appear in Applications for a factual check before sending.</p>
        <?php endif; ?>
        <?php if ($automationStatus['mode'] === 'automatic'): ?><p class="soft-note"><?= $automationStatus['smtp_ready'] ? 'Strong eligible matches that need a manual application will be emailed to your account email. Automation continues with eligible email applications.' : 'Manual-application email alerts are waiting for the site owner to finish email setup. Matching and email applications can continue.'; ?></p><?php endif; ?>
        <?php if (!$automationStatus['email_jobs']): ?><p class="soft-note">No open email applications are currently saved. Jobs that require an employer website remain available for manual application.</p><?php endif; ?>
        <a class="btn btn-small" href="<?= e(app_url('/applications')); ?>">View applications</a>
    </div>
</section>
