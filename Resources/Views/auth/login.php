<div class="auth-card">
    <div class="eyebrow">YOUR PERSONAL CAREER WORKSPACE</div>
    <h1>Welcome back.</h1>
    <p>A fresh start, and your next opportunity. Sign in to keep things moving.</p>
    <form action="/login" method="post" data-async class="form-stack">
        <div class="form-error" role="alert" hidden></div>
        <div class="field">
            <label for="email">Email address</label>
            <input
                id="email"
                type="email"
                name="email"
                autocomplete="username"
                placeholder="you@example.com"
                required
                autofocus
            />
        </div>
        <div class="field">
            <label for="password">Password</label>
            <div class="password-wrap">
                <input
                    id="password"
                    type="password"
                    name="password"
                    autocomplete="current-password"
                    placeholder="Enter your password"
                    required
                />
                <button
                    type="button"
                    class="icon-button"
                    data-toggle-password="password"
                    aria-label="Show password"
                    aria-pressed="false"
                >
                    <?= icon('eye'); ?>
                </button>
            </div>
        </div>
        <label class="check-label">
            <input type="checkbox" name="remember" value="1" />
            Keep me signed in for 30 days
        </label>
        <button class="btn btn-primary" type="submit" data-loading="Signing you in…">
            Sign in to your workspace <?= icon('arrow-right'); ?>
        </button>
    </form>
    <div class="auth-security"><?= icon('lock-keyhole'); ?>Private by design. Your career, in your hands.</div>
    <div class="onboarding-line">
        First time here? Create your administrator with
        <br />
        <code>php scripts/create-admin.php</code>
    </div>
</div>
