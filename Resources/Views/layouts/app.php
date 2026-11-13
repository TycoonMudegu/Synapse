<?php
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
]; ?>
<!doctype html>
<html lang="en-GB">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="csrf-token" content="<?= e(\App\Services\Security\Csrf::token()); ?>" />
        <meta name="theme-color" content="#326080" />
        <title><?= e($title ?? 'Workspace'); ?> · JobFlow</title>
        <link rel="icon" href="/Assets/favicon.svg" type="image/svg+xml" />
        <link rel="stylesheet" href="/Assets/app.css" />
        <script type="module" src="/Assets/app.js"></script>
    </head>
    <body>
        <a class="sr-only focus:not-sr-only" href="#main-content">Skip to content</a>
        <button
            class="sidebar-overlay"
            id="sidebar-overlay"
            aria-label="Close navigation"
            hidden
        ></button>
        <aside class="sidebar" id="sidebar" aria-label="Main navigation">
            <a class="brand" href="/">
                <span class="brand-mark"><?= icon('arrow-up-right'); ?></span>
                JobFlow
                <span>.</span>
            </a>
            <p class="sidebar-caption">Make your next move</p>
            <p class="nav-label">YOUR WORKSPACE</p>
            <nav class="nav-list">
                <?php foreach ($navigation as [$key, $href, $iconName, $name]): ?>
                <a href="<?= e($href); ?>" class="nav-link <?= ($active ?? '') === $key ? 'active' : ''; ?>" <?= ($active ?? '') === $key ? 'aria-current="page"' : ''; ?>>
                    <?= icon($iconName); ?>
                    <span><?= e($name); ?></span>
                </a>
                <?php endforeach; ?>
            </nav>
            <div class="sidebar-bottom">
                <div class="sidebar-note">
                    <div class="flex items-center gap-2">
                        <?= icon('sprout'); ?>
                        <strong class="text-[11px] text-earth">Small steps. Real progress.</strong>
                    </div>
                    <p>The right opportunity is worth a thoughtful application.</p>
                </div>
                <div class="sidebar-profile">
                    <div class="avatar"><?= e(initials($currentUser['name'])); ?></div>
                    <a href="/settings">
                        <div class="profile-name"><?= e($currentUser['name']); ?></div>
                        <div class="text-[10px] text-[#a28b76]">Personal workspace</div>
                    </a>
                    <form action="/logout" method="post" class="ml-auto">
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
                        id="menu-toggle"
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
                    <a href="/activity" class="icon-button" aria-label="View notifications">
                        <?= icon('bell'); ?>
                    </a>
                    <a href="/profile" class="avatar" aria-label="Open your profile">
                        <?= e(initials($currentUser['name'])); ?>
                    </a>
                </div>
            </header>
            <main class="workspace" id="main-content">
                <div id="task-region" aria-live="polite"></div>
                <?= $content; ?>
                <footer class="footer-note">
                    <span>One thoughtful application at a time.</span>
                    <span><?= icon('map-pin'); ?>Kenya · Africa/Nairobi</span>
                </footer>
            </main>
        </div>
        <div class="toast-region" aria-live="polite" id="toasts"></div>
        <dialog id="upload-dialog" aria-labelledby="upload-title">
            <div class="dialog-header">
                <h2 id="upload-title">Upload a document</h2>
                <button class="icon-button" data-close-dialog aria-label="Close">
                    <?= icon('x'); ?>
                </button>
            </div>
            <form
                action="/api/documents"
                method="post"
                enctype="multipart/form-data"
                data-async
                class="panel-body form-stack"
            >
                <div class="form-error" role="alert" hidden></div>
                <div class="field">
                    <label for="upload-kind">Document type</label>
                    <select name="kind" id="upload-kind">
                        <option value="master_cv">Master CV</option>
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
