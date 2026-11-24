<?php
$membership = (new \App\Repositories\AccountRepository())->membership(\App\Services\Security\Auth::id());
$navigation = [
    ['dashboard', '/', 'layout-dashboard', 'Dashboard'],
    ['discover', '/discover', 'compass', 'Discover jobs'],
    ['jobs', '/jobs', 'briefcase-business', 'My jobs'],
    ['profile', '/profile', 'contact-round', 'My CV'],
    ['applications', '/applications', 'send', 'Applications'],
    ['documents', '/documents', 'files', 'Documents'],
    ['inbox', '/inbox', 'inbox', 'Inbox'],
    ['activity', '/activity', 'activity', 'Activity'],
    ['settings', '/settings', 'settings-2', 'Settings'],
    ['plans', '/plans', 'star', 'Plans & billing'],
]; ?>
<!doctype html>
<html lang="en-GB">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="csrf-token" content="<?= e(\App\Services\Security\Csrf::token()); ?>" />
        <meta name="app-base-path" content="<?= e(app_base_path()); ?>" />
        <meta name="theme-color" content="#326080" />
        <title><?= e($title ?? 'Workspace'); ?> · Synapse</title>
        <link rel="icon" href="<?= e(app_base_path()); ?>/Assets/favicon.svg" type="image/svg+xml" />
        <link rel="stylesheet" href="<?= e(app_base_path()); ?>/Assets/app.css" />
        <?php require BASE_PATH . '/Resources/Views/components/alpine.php'; ?>
    </head>
    <body class="<?= e($bodyClass ?? ''); ?>" x-data="appShell" x-on:keydown.escape.window="menuOpen = false">
        <a class="sr-only focus:not-sr-only" href="#main-content">Skip to content</a>
        <button
            class="sidebar-overlay"
            id="sidebar-overlay" x-on:click="menuOpen = false" x-bind:hidden="!menuOpen"
            aria-label="Close navigation"
            hidden
        ></button>
        <aside class="sidebar" id="sidebar" x-bind:class="{ open: menuOpen }" aria-label="Main navigation">
            <a class="brand" href="<?= e(app_base_path()); ?>/">
                <span class="brand-mark"><?= icon('arrow-up-right'); ?></span>
                Synapse
                <span>.</span>
            </a>
            <p class="sidebar-caption">Make your next move</p>
            <p class="nav-label">YOUR WORKSPACE</p>
            <nav class="nav-list">
                <?php foreach ($navigation as [$key, $href, $iconName, $name]): ?>
                <a href="<?= e(app_url($href)); ?>" class="nav-link <?= ($active ?? '') === $key ? 'active' : ''; ?>" <?= ($active ?? '') === $key ? 'aria-current="page"' : ''; ?>>
                    <?= icon($iconName); ?>
                    <span><?= e($name); ?></span>
                </a>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-bottom">
                <a class="sidebar-plan" href="<?= e(app_url('/plans')); ?>"><?= icon('sparkles'); ?><span><?= $membership['active'] ? 'Synapse Plus' : 'Free plan'; ?><small><?= $membership['active'] ? 'A little more momentum' : 'Explore Plus from KSh 200'; ?></small></span></a>
                <div class="sidebar-note">
                    <div class="flex items-center gap-2">
                        <?= icon('sprout'); ?>
                        <strong class="text-[11px] text-earth">Small steps. Real progress.</strong>
                    </div>
                    <p>The right opportunity is worth a thoughtful application.</p>
                </div>
                <div class="sidebar-profile">
                    <div class="avatar"><?= e(initials($currentUser['name'])); ?></div>
                    <a href="<?= e(app_base_path()); ?>/settings">
                        <div class="profile-name"><?= e($currentUser['name']); ?></div>
                        <div class="text-[10px] text-[#a28b76]">Private workspace</div>
                    </a>
                    <form action="<?= e(app_base_path()); ?>/logout" method="post" class="ml-auto">
                        <?= csrf_field(); ?>
                        <button class="icon-button" aria-label="Sign out" title="Sign out">
                            <?= icon('log-out'); ?>
                        </button>
                    </form>
                </div>
            </div>
        </aside>
        <div class="main-shell">
            <header class="topbar">
                <div class="breadcrumbs">
                    <button
                        class="icon-button mobile-menu"
                        id="menu-toggle" x-on:click="toggleMenu" x-bind:aria-expanded="menuOpen"
                        aria-label="Open navigation"
                        aria-controls="sidebar"
                        aria-expanded="false"
                    >
                        <?= icon('menu'); ?>
                    </button>
                    <?= icon('house', 'w-3.5 h-3.5'); ?>
                    <span>Workspace</span>
                    <span>/</span>
                    <strong><?= e(($active ?? '') === 'dashboard' ? 'Overview' : $title); ?></strong>
                </div>
                <div class="topbar-actions">
                    <span class="date-label"><?= icon('calendar-days', 'w-3.5 h-3.5'); ?><?= e(date('D, d M Y')); ?></span>
                    <a href="<?= e(app_base_path()); ?>/activity" class="icon-button" aria-label="View notifications">
                        <?= icon('bell'); ?>
                    </a>
                    <a href="<?= e(app_base_path()); ?>/profile" class="avatar" aria-label="Open your profile">
                        <?= e(initials($currentUser['name'])); ?>
                    </a>
                </div>
            </header>
            <main class="workspace" id="main-content">
                <div id="task-region" aria-live="polite" x-bind:hidden="!$store.app.taskText" hidden>
                    <div class="task-progress"><span class="spinner" aria-hidden="true" x-bind:hidden="!$store.app.taskSpinning"></span><span x-text="$store.app.taskText"></span></div>
                </div>
                <?= $content; ?>
                <footer class="footer-note">
                    <span>One thoughtful application at a time.</span>
                    <span><?= icon('map-pin'); ?>Kenya · Africa/Nairobi</span>
                </footer>
            </main>
        </div>
        <?php require BASE_PATH . '/Resources/Views/components/feedback.php'; ?>
        <dialog id="upload-dialog" aria-labelledby="upload-title">
            <div class="dialog-header">
                <h2 id="upload-title">Upload a document</h2>
                <button class="icon-button" x-on:click="$store.app.closeDialog($el)" aria-label="Close">
                    <?= icon('x'); ?>
                </button>
            </div>
            <form
                action="<?= e(app_base_path()); ?>/api/documents"
                method="post"
                enctype="multipart/form-data"
                x-data="asyncForm" x-on:submit.prevent="submit($event)"
                class="panel-body form-stack"
            >
                <div class="form-error" role="alert" hidden></div>
                <div class="field">
                    <label for="upload-kind">Document type</label>
                    <select name="kind" id="upload-kind">
                        <option value="master_cv">Master CV</option>
                        <option value="cover_letter_template">Cover letter template</option>
                        <option value="supporting">Supporting document</option>
                        <option value="job_poster">Job poster</option>
                    </select>
                </div>
                <div class="upload-zone">
                    <?= icon('cloud-upload'); ?>
                    <h3>A home for your next chapter</h3>
                    <p>
                        PDF, DOCX, JPG, PNG or WEBP. Maximum <?= e(\App\Core\Env::get('UPLOAD_MAX_MB', '10')); ?> MB.
                        <br />
                        CVs must be PDF or DOCX.
                    </p>
                    <input
                        type="file"
                        name="file"
                        accept=".pdf,.docx,.jpg,.jpeg,.png,.webp"
                        aria-label="Choose a document"
                        required
                    />
                </div>
                <button class="btn btn-primary" type="submit" data-loading="Storing your document…">
                    <?= icon('upload'); ?>Upload document
                </button>
            </form>
        </dialog>
    </body>
</html>
