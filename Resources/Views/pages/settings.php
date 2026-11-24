<div class="page-heading">
    <div>
        <div class="eyebrow mb-2">MAKE SYNAPSE YOUR OWN</div>
        <h1>Settings</h1>
        <p>A few thoughtful choices. A workspace that works the way you do.</p>
    </div>
</div>
<nav class="settings-tabs" aria-label="Settings sections">
    <?php foreach (
    [
        'general' => 'General',
        'discovery' => 'Job discovery',
        'ai' => 'AI providers',
        'gmail' => 'Gmail',
        'automation' => 'Automation',
        'documents' => 'Documents',
        'security' => 'Security',

    ]
    as $key => $name
): ?>
    <a class="<?= $tab === $key ? 'active' : ''; ?>" href="<?= e(app_base_path()); ?>/settings?tab=<?= e($key); ?>" <?= $tab === $key ? 'aria-current="page"' : ''; ?>>
        <?= e($name); ?>
    </a>
    <?php endforeach; ?>
</nav>
<div class="settings-section">
    <?php
$inputField = static function (
    string $name,
    string $title,
    mixed $value,
    string $type = 'text',
    string $hint = '',
): void {
    ?>
    <div class="field">
        <label for="setting-<?= e($name); ?>"><?= e($title); ?></label>
        <input
            id="setting-<?= e($name); ?>"
            name="<?= e($name); ?>"
            type="<?= e($type); ?>"
            value="<?= e($value); ?>"
            <?= $type === 'number' ? 'step="any" min="0"' : ''; ?>
        />
        <?php if (
    $hint
): ?>
        <small><?= e($hint); ?></small>
        <?php endif; ?>
    </div>
    <?php
};
$checkField = static function (string $name, string $title, bool $checked): void {
    ?>
    <label class="check-label">
        <input type="checkbox" name="<?= e($name); ?>" value="1" <?= $checked ? 'checked' : ''; ?> />
        <?= e($title); ?>
    </label>
    <?php
};
if ($tab === 'automation' && !(new \App\Services\Entitlements())->paid(\App\Services\Security\Auth::id())) {
    require BASE_PATH . '/Resources/Views/components/upgrade.php';
} else {
    require BASE_PATH . '/Resources/Views/pages/settings/' . $tab . '.php';
}
?>
</div>
