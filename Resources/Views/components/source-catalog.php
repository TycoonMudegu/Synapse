<?php
$installedSources = [];
foreach ($sources as $installedSource) {
    if ($installedSource['preset_key']) {
        $installedSources[$installedSource['preset_key']] = $installedSource;
    }
}
?>
<section class="mb-6" aria-label="Kenyan job sources">
    <div class="page-heading mb-4">
        <div>
            <h2>Your Kenyan job sources</h2>
            <p>Collect jobs from public pages and feeds. Email alerts are optional. New jobs appear in Discover and Activity.</p>
        </div>
        <?php if (count($installedSources) < count($sourceCatalog)): ?>
            <button class="btn btn-soft" data-action="<?= e(app_url('/api/sources/presets')); ?>" data-loading="Adding and checking sources…">
                <?= icon('plus'); ?>Add all three sources
            </button>
        <?php endif; ?>
    </div>
    <div class="document-grid">
        <?php foreach ($sourceCatalog as $sourceKey => $definition): ?>
            <?php
            $installedSource = $installedSources[$sourceKey] ?? null;
            $emailSource = ($installedSource['adapter'] ?? $definition['adapter']) === 'email_alert';
            $publicPages = in_array($sourceKey, ['brightermonday', 'fuzu'], true) && !$emailSource;
            $sourceState = $installedSource ? label($installedSource['health']) : 'Not added';
            if ($installedSource && !$installedSource['enabled'] && $installedSource['health'] === 'connected') {
                $sourceState = 'Disabled';
            }
            ?>
            <article class="document-card">
                <?= icon($emailSource ? 'mail' : 'radio'); ?>
                <div class="flex items-start justify-between gap-3">
                    <h3><?= e($definition['name']); ?></h3>
                    <span class="badge <?= $installedSource && $installedSource['enabled'] && $installedSource['health'] === 'connected' ? 'badge-green' : 'badge-warm'; ?>"><?= e($sourceState); ?></span>
                </div>
                <p><?= e($emailSource ? \App\Services\Discovery\SourceCatalog::get($sourceKey, 'email')['description'] : $definition['description']); ?></p>
                <p><strong><?= $emailSource ? 'Email alerts' : ($publicPages ? 'Public pages' : 'Official RSS'); ?></strong></p>
                <?php if ($installedSource): ?>
                    <?php if ($installedSource['last_error']): ?>
                        <p class="text-earth"><?= e($installedSource['last_error']); ?></p>
                    <?php endif; ?>
                    <?php if ($installedSource['last_success_at']): ?>
                        <p>Last successful check: <?= e(date('d M, H:i', strtotime($installedSource['last_success_at']))); ?> EAT</p>
                    <?php endif; ?>
                    <?php foreach ($alertCounts ?? [] as $alertCount): ?>
                        <?php if ((int) $alertCount['source_id'] === (int) $installedSource['id']): ?>
                            <p><?= e(label($alertCount['status'])); ?>: <?= e($alertCount['total']); ?> linked vacancies</p>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                <div class="heading-actions">
                    <?php if (!$installedSource): ?>
                        <button class="btn btn-primary btn-sm" data-action="<?= e(app_url('/api/sources/presets')); ?>" data-payload="<?= e(json_encode(['preset' => $sourceKey], JSON_THROW_ON_ERROR)); ?>" data-loading="Adding source…">Add source</button>
                    <?php else: ?>
                        <?php if ($emailSource && !$gmailConnected): ?>
                            <a class="btn btn-primary btn-sm" href="<?= e(app_url('/settings?tab=gmail')); ?>">Connect Gmail</a>
                        <?php else: ?>
                            <button class="btn btn-soft btn-sm" data-action="<?= e(app_url('/api/sources/' . $installedSource['id'])); ?>" data-payload='{"action":"test"}' data-loading="Checking connection…"><?= $emailSource ? 'Check alerts' : ($publicPages ? 'Check public pages' : 'Check feed'); ?></button>
                        <?php endif; ?>
                        <button class="btn btn-sm" data-action="<?= e(app_url('/api/sources/' . $installedSource['id'])); ?>" data-payload='{"action":"toggle"}'><?= $installedSource['enabled'] ? 'Disable' : 'Enable'; ?></button>
                        <button class="icon-button" aria-label="Remove <?= e($definition['name']); ?> from JobFlow" data-action="<?= e(app_url('/api/sources/' . $installedSource['id'])); ?>" data-payload='{"action":"delete"}' data-confirm="Remove this source from JobFlow? Saved jobs stay. Manage any email subscription on the original website."><?= icon('trash-2'); ?></button>
                        <?php if ($emailSource): ?>
                            <button class="btn btn-sm" data-action="<?= e(app_url('/api/sources/' . $installedSource['id'])); ?>" data-payload='{"action":"retry_alerts"}'>Retry failed imports</button>
                        <?php endif; ?>
                        <?php if (in_array($sourceKey, ['brightermonday', 'fuzu'], true)): ?>
                            <button class="btn btn-sm" data-action="<?= e(app_url('/api/sources/' . $installedSource['id'])); ?>" data-payload="<?= e(json_encode(['action' => $emailSource ? 'public_pages' : 'email_alerts'], JSON_THROW_ON_ERROR)); ?>"><?= $emailSource ? 'Use public pages' : 'Use email alerts'; ?></button>
                        <?php endif; ?>
                    <?php endif; ?>
                    <a class="text-link" href="<?= e($publicPages ? $definition['url'] : $definition['setup_url']); ?>" target="_blank" rel="noopener noreferrer"><?= $emailSource ? 'Set up official alerts' : ($publicPages ? 'Browse jobs' : 'Official RSS information'); ?><?= icon('external-link'); ?></a>
                </div>
                <?php if ($emailSource): ?>
                    <p>Connect Gmail with monitoring enabled, then complete the site's subscription and email verification yourself. Direct job links are imported; unsupported tracking links remain in the inbox for review.</p>
                <?php elseif ($publicPages): ?>
                    <p>Reads public technical job pages with a request cap and delay. A blocked request or incomplete vacancy is reported instead of creating a job.</p>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>
