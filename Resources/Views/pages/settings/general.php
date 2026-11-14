<section class="panel">
    <div class="panel-header">
        <div>
            <h2>Your personal workspace</h2>
            <p>A simple home for a thoughtful job search.</p>
        </div>
    </div>
    <div class="panel-body">
        <div class="flex items-center gap-4 mb-6">
            <div class="avatar w-12 h-12"><?= e(initials($currentUser['name'])); ?></div>
            <div>
                <h3><?= e($currentUser['name']); ?></h3>
                <p class="muted text-xs"><?= e($currentUser['email']); ?></p>
            </div>
        </div>
        <div class="definition-row">
            <span>Timezone</span>
            <strong>Africa/Nairobi (EAT · UTC+3)</strong>
        </div>
        <div class="definition-row">
            <span>Primary market</span>
            <strong>Kenya</strong>
        </div>
        <div class="definition-row">
            <span>Interface language</span>
            <strong>British English</strong>
        </div>
        <div class="form-actions">
            <a href="<?= e(app_base_path()); ?>/profile" class="btn btn-primary">
                <?= icon('contact-round'); ?>Edit career profile & preferences
            </a>
        </div>
    </div>
</section>
