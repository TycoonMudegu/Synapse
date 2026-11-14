<?php $emailSources = array_filter($sources, static fn ($source) => $source['adapter'] === 'email_alert' && $source['enabled']); ?>
<?php if ($emailSources): ?>
    <div class="notice">
        <?= icon('radio'); ?>
        <div>
            <strong>Job alerts from <?= e(implode(', ', array_column($emailSources, 'name'))); ?></strong>
            <p>Use this Gmail address for each site's official job-alert subscription. After connecting with read-only monitoring, alerts appear in your application inbox. A configured AI screening provider can turn the linked vacancies into job cards.</p>
            <a class="text-link" href="<?= e(app_url('/settings?tab=discovery')); ?>">Manage job sources <?= icon('arrow-right'); ?></a>
        </div>
    </div>
<?php endif; ?>
<section class="panel">
    <div class="panel-header">
        <h2>Gmail connection</h2>
        <span class="badge <?= $connection ? 'badge-green' : ''; ?>"><?= $connection ? 'Connected' : 'Not connected'; ?></span>
    </div>
    <div class="panel-body">
        <div class="flex items-center gap-4 mb-5">
            <span class="empty-icon mb-0"><?= icon('mail'); ?></span>
            <div>
                <h3><?= $connection ? e($connection['account_email']) : 'A secure connection to your sending account'; ?></h3>
                <p class="muted text-xs mt-1"><?= $gmailConfigured ? 'Google OAuth configuration is available.' : 'Google OAuth credentials have not been configured on the server.'; ?></p>
            </div>
        </div>
        <?php if (
    !$gmailConfigured
): ?>
        <div class="soft-note mb-5">
            Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and GOOGLE_REDIRECT_URI in your server
            environment. Register the exact callback URL in Google Cloud. See docs/integrations.md.
        </div>
        <?php endif; ?>
        <div class="heading-actions">
            <form action="<?= e(app_base_path()); ?>/gmail/connect" method="post">
                <?= csrf_field(); ?>
                <button class="btn btn-primary" <?= $gmailConfigured ? '' : 'disabled'; ?>>
                    <?= icon('link'); ?><?= $connection ? 'Reconnect / choose another account' : 'Connect Gmail'; ?>
                </button>
            </form>
            <?php if (
    $connection
): ?>
            <button
                class="btn"
                data-action="<?= e(app_base_path()); ?>/api/gmail/test"
                data-confirm="Send a connection test email to your connected Gmail account?"
                data-loading="Sending test email…"
            >
                Send test email
            </button>
            <button
                class="btn btn-danger"
                data-action="<?= e(app_base_path()); ?>/api/gmail/disconnect"
                data-confirm="Disconnect Gmail and remove stored tokens?"
            >
                Disconnect
            </button>
            <?php endif; ?>
        </div>
    </div>
</section>
<section class="panel">
    <div class="panel-header"><h2>Sending & relevant correspondence</h2></div>
    <form action="<?= e(app_base_path()); ?>/api/settings" data-async class="panel-body form-stack">
        <input type="hidden" name="section" value="gmail" />
        <div class="form-error" hidden role="alert"></div>
        <div class="field">
            <label for="gmail-signature">Email signature</label>
            <textarea name="signature" id="gmail-signature"><?= e($settings['signature']); ?></textarea>
        </div>
        <?php
$checkField(
    'monitor_enabled',
    'Monitor enabled job-source alerts and emails matched by my additional search query.',
    $settings['monitor_enabled'],
);
$checkField(
    'response_tracking',
    'Read replies in known JobFlow application threads.',
    $settings['response_tracking'],
);
$inputField(
    'alert_query',
    'Additional Gmail query for job alerts (optional with enabled sources)',
    $settings['alert_query'],
    'text',
    'Use specific senders or a dedicated label. Only matching messages from the last 30 days are imported.',
);
$inputField(
    'sync_minutes',
    'Synchronisation interval (minutes)',
    $settings['sync_minutes'],
    'number',
);
?>
        <div class="soft-note">
            Sending requests gmail.send. Monitoring adds gmail.readonly only when enabled. Save
            these preferences, then reconnect to grant any additional permissions. JobFlow never
            modifies unrelated email. Possible interview invitations are flagged for your review.
        </div>
        <div class="form-actions">
            <button class="btn btn-primary">Save Gmail preferences</button>
        </div>
    </form>
</section>
