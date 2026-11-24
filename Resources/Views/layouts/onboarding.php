<!doctype html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e(\App\Services\Security\Csrf::token()); ?>">
    <meta name="app-base-path" content="<?= e(app_base_path()); ?>">
    <meta name="theme-color" content="#326080">
    <title><?= e($title); ?> · Synapse</title>
    <link rel="icon" href="<?= e(app_url('/Assets/favicon.svg')); ?>" type="image/svg+xml">
    <link rel="stylesheet" href="<?= e(app_url('/Assets/app.css')); ?>">
    <?php require BASE_PATH . '/Resources/Views/components/alpine.php'; ?>
</head>
<body class="setup-body <?= !empty($setupFlow) ? 'setup-flow' : ''; ?>" x-data="appShell" x-on:keydown.escape.window="menuOpen = false">
    <header class="setup-header">
        <a class="brand" href="<?= e(app_url('/onboarding')); ?>"><span class="brand-mark"><?= icon('sprout'); ?></span>Synapse<span>.</span></a>
        <div class="setup-account"><span><?= e($currentUser['name']); ?></span><form action="<?= e(app_url('/logout')); ?>" method="post"><?= csrf_field(); ?><button class="btn btn-small" type="submit">Sign out</button></form></div>
    </header>
    <main class="setup-main" id="main-content">
        <div id="task-region" aria-live="polite" x-bind:hidden="!$store.app.taskText" hidden><div class="task-progress"><span class="spinner" aria-hidden="true" x-bind:hidden="!$store.app.taskSpinning"></span><span x-text="$store.app.taskText"></span></div></div>
        <?= $content; ?>
    </main>
    <footer class="setup-footer"><?= icon('lock-keyhole'); ?>A private workspace for your next move. Built in Kenya.</footer>
    <?php require BASE_PATH . '/Resources/Views/components/feedback.php'; ?>
</body>
</html>
