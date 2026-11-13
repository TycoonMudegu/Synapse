<section class="panel">
    <div class="panel-header"><h2>Change password</h2></div>
    <form action="/api/security/password" data-async class="panel-body form-stack">
        <div class="form-error" hidden role="alert"></div>
        <div class="field">
            <label for="current-password">Current password</label>
            <input
                type="password"
                id="current-password"
                name="current_password"
                autocomplete="current-password"
                required
            />
        </div>
        <div class="form-grid">
            <div class="field">
                <label for="new-password">New password</label>
                <input
                    type="password"
                    id="new-password"
                    name="password"
                    autocomplete="new-password"
                    minlength="12"
                    maxlength="72"
                    required
                />
            </div>
            <div class="field">
                <label for="confirm-password">Confirm new password</label>
                <input
                    type="password"
                    id="confirm-password"
                    name="password_confirmation"
                    autocomplete="new-password"
                    minlength="12"
                    maxlength="72"
                    required
                />
            </div>
        </div>
        <p class="muted text-xs">
            Use 12–72 characters. Other sessions and remembered sign-ins are revoked after a
            password change.
        </p>
        <div class="form-actions"><button class="btn btn-primary">Update password</button></div>
    </form>
</section>
<section class="panel">
    <div class="panel-header">
        <h2>Account sessions</h2>
        <button
            class="btn btn-sm"
            data-action="/api/security/sessions/revoke"
            data-confirm="Sign out all other sessions and revoke remembered sign-ins?"
        >
            Revoke other sessions
        </button>
    </div>
    <?php foreach (
    $sessions
    as $session
): ?>
    <div class="activity-row">
        <?= icon('monitor', 'text-[#8ca6b7]'); ?>
        <div>
            <h3><?= e($session['user_agent']); ?></h3>
            <small>Created <?= e(date('d M Y, H:i', strtotime($session['created_at']))); ?> EAT<?= hash_equals($session['session_hash'], hash('sha256', session_id())) ? ' · Current session' : ''; ?></small>
        </div>
    </div>
    <?php endforeach; ?>
</section>
