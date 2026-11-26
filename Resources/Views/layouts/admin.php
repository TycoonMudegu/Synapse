<?php
$navigation = [
    ['jobs', '/admin', 'library', 'Jobs'],
    ['sources', '/admin?tab=sources', 'radio', 'Sources'],
    ['runs', '/admin?tab=runs', 'refresh-cw', 'Collection runs'],
];
?>
<!doctype html>
<html lang="en-GB">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="csrf-token" content="<?= e(\App\Services\Security\Csrf::token()); ?>" />
        <meta name="app-base-path" content="<?= e(app_base_path()); ?>" />
        <meta name="theme-color" content="#326080" />
        <title><?= e($title); ?> · Synapse Admin</title>
        <link rel="icon" href="<?= e(app_url('/Assets/favicon.svg')); ?>" type="image/svg+xml" />
        <link rel="stylesheet" href="<?= e(app_url('/Assets/app.css')); ?>" />
        <?php require BASE_PATH . '/Resources/Views/components/alpine.php'; ?>
    </head>
    <body x-data="appShell" x-on:keydown.escape.window="menuOpen = false">
        <a class="sr-only focus:not-sr-only" href="#main-content">Skip to content</a>
        <button class="sidebar-overlay" x-on:click="menuOpen = false" x-bind:hidden="!menuOpen" aria-label="Close navigation" hidden></button>
        <aside class="sidebar" id="sidebar" x-bind:class="{ open: menuOpen }" aria-label="Administration navigation">
            <a class="brand" href="<?= e(app_url('/admin')); ?>"><span class="brand-mark"><?= icon('shield-check'); ?></span>Synapse<span>.</span></a>
            <p class="sidebar-caption">Keep opportunities moving</p>
            <p class="nav-label">ADMINISTRATION</p>
            <nav class="nav-list">
                <?php foreach ($navigation as [$key, $href, $iconName, $name]): ?>
                <a href="<?= e(app_url($href)); ?>" class="nav-link <?= ($adminSection ?? 'jobs') === $key ? 'active' : ''; ?>" <?= ($adminSection ?? 'jobs') === $key ? 'aria-current="page"' : ''; ?>><?= icon($iconName); ?><span><?= e($name); ?></span></a>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-note"><strong>Daily job collection</strong><p>7:00 AM · East Africa Time</p></div>
                <div class="sidebar-profile">
                    <div class="avatar"><?= e(initials($currentUser['name'])); ?></div>
                    <div><div class="profile-name"><?= e($currentUser['name']); ?></div><div class="text-[10px] text-[#a28b76]">Administrator</div></div>
                    <form action="<?= e(app_url('/logout')); ?>" method="post" class="ml-auto"><?= csrf_field(); ?><button class="icon-button" aria-label="Sign out" title="Sign out"><?= icon('log-out'); ?></button></form>
                </div>
            </div>
        </aside>
        <div class="main-shell">
            <header class="topbar">
                <div class="breadcrumbs"><button class="icon-button mobile-menu" x-on:click="toggleMenu" x-bind:aria-expanded="menuOpen" aria-label="Open navigation" aria-expanded="false"><?= icon('menu'); ?></button><?= icon('shield-check', 'w-3.5 h-3.5'); ?><span>Admin portal</span><span>/</span><strong><?= e($title); ?></strong></div>
                <span class="date-label"><?= icon('calendar-days', 'w-3.5 h-3.5'); ?><?= e(date('D, d M Y')); ?></span>
            </header>
            <main class="workspace" id="main-content">
                <?= $content; ?>
                <footer class="footer-note"><span>Synapse operations</span><span><?= icon('map-pin'); ?>Kenya · Africa/Nairobi</span></footer>
            </main>
        </div>
        <?php require BASE_PATH . '/Resources/Views/components/feedback.php'; ?>
    </body>
</html>
