<?php $googleReady = \App\Services\Security\GoogleSignIn::configured(); ?>
<form action="<?= e(app_url('/auth/google')); ?>" method="post">
    <?= csrf_field(); ?>
    <button class="btn google-signin" type="submit" <?= !$googleReady ? 'disabled' : ''; ?>>
        <img class="google-logo" src="<?= e(app_url('/Assets/google-g.png')); ?>" width="20" height="20" alt="" aria-hidden="true"> Continue with Google
    </button>
    <?php if (!$googleReady): ?><p class="auth-hint">Continue with your email while Google is being connected.</p><?php endif; ?>
</form>
<div class="auth-divider"><span>or use your email</span></div>
