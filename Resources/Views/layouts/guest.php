<!doctype html>
<html lang="en-GB">
    <head>
        <meta charset="UTF-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="csrf-token" content="<?= e(\App\Services\Security\Csrf::token()); ?>" />
        <meta name="app-base-path" content="<?= e(app_base_path()); ?>" />
        <meta name="theme-color" content="#326080" />
        <title><?= e($title ?? 'Welcome'); ?> · JobFlow</title>
        <link rel="icon" href="<?= e(app_base_path()); ?>/Assets/favicon.svg" type="image/svg+xml" />
        <link rel="stylesheet" href="<?= e(app_base_path()); ?>/Assets/app.css" />
        <script type="module" src="<?= e(app_base_path()); ?>/Assets/app.js"></script>
    </head>
    <body class="auth-body">
        <div class="auth-layout">
            <aside class="auth-story">
                <a href="<?= e(app_base_path()); ?>/" class="brand">
                    <span class="brand-mark"><?= icon('arrow-up-right'); ?></span>
                    JobFlow
                    <span>.</span>
                </a>
                <div class="story-content">
                    <div class="auth-art"><?= icon('sprout'); ?></div>
                    <div class="eyebrow">A little direction. A world of possibility.</div>
                    <h2>
                        Your next chapter
                        <br />
                        starts with
                        <em>one step.</em>
                    </h2>
                    <p class="story-copy">
                        Find the right opportunities, put your best self forward, and keep every
                        application moving.
                    </p>
                </div>
                <footer>Built for your ambition. Rooted in Kenya.</footer>
            </aside>
            <main class="auth-form-side"><?= $content; ?></main>
        </div>
        <div class="toast-region" aria-live="polite" id="toasts"></div>
    </body>
</html>
