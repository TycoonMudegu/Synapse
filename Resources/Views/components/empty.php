<div class="empty-state">
    <div class="empty-icon"><?= icon($iconName); ?></div>
    <h3><?= e($title); ?></h3>
    <p><?= e($description); ?></p>
    <?php if ($href && $action): ?>
    <a class="btn btn-soft" href="<?= e($href); ?>"><?= e($action); ?><?= icon('arrow-up-right'); ?></a>
    <?php endif; ?>
</div>
