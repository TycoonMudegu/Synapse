<?php
$authStage = in_array($authStage ?? '', ['password', 'register'], true) ? $authStage : 'email';
$headings = ['email' => 'Your next move starts here.', 'password' => 'Welcome back.', 'register' => 'Make yourself at home.'];
$descriptions = ['email' => 'One place for your search, your experience and what comes next.', 'password' => 'Enter your password to continue to your workspace.', 'register' => 'A few details, then your next chapter can begin.'];
?>
<div class="auth-card unified-auth" x-data="entryFlow" data-stage="<?= e($authStage); ?>">
    <div class="eyebrow">YOUR PERSONAL CAREER WORKSPACE</div>
    <h1 x-text="heading" tabindex="-1" x-ref="heading"><?= e($headings[$authStage]); ?></h1>
    <p x-text="description"><?= e($descriptions[$authStage]); ?></p>
    <div class="auth-google-option" x-bind:hidden="stage !== 'email'" <?= $authStage !== 'email' ? 'hidden' : ''; ?>>
        <?php require BASE_PATH . '/Resources/Views/components/google-signin.php'; ?>
    </div>
    <form action="<?= e(app_url('/auth/continue')); ?>" method="post" x-on:submit.prevent="submit($event)" class="form-stack">
        <?= csrf_field(); ?>
        <input type="hidden" name="stage" value="<?= $authStage === 'email' ? 'email' : 'complete'; ?>" x-bind:value="stage === 'email' ? 'email' : 'complete'">
        <div class="form-error" role="alert" <?= empty($authError) ? 'hidden' : ''; ?>><?= e($authError ?? ''); ?></div>
        <div class="field">
            <div class="auth-email-label"><label for="email">Email address</label><button type="button" class="text-link" x-on:click="changeEmail" x-bind:hidden="stage === 'email'" <?= $authStage === 'email' ? 'hidden' : ''; ?>>Change</button></div>
            <input id="email" name="email" type="email" autocomplete="username" maxlength="254" placeholder="you@example.com" value="<?= e($authEmail ?? ''); ?>" x-ref="email" x-bind:readonly="stage !== 'email'" <?= $authStage !== 'email' ? 'readonly' : ''; ?> required autofocus>
        </div>
        <div class="field" x-bind:hidden="stage !== 'register'" <?= $authStage !== 'register' ? 'hidden' : ''; ?>>
            <label for="name">Your name</label><input id="name" name="name" autocomplete="name" maxlength="150" placeholder="What should we call you?" x-ref="name" x-bind:disabled="stage !== 'register'" x-bind:required="stage === 'register'" <?= $authStage !== 'register' ? 'disabled' : 'required'; ?>>
        </div>
        <div class="field" x-bind:hidden="stage === 'email'" <?= $authStage === 'email' ? 'hidden' : ''; ?>>
            <label for="password">Password</label>
            <div class="password-wrap">
                <input id="password" name="password" type="password" x-bind:type="visible ? 'text' : 'password'" x-bind:autocomplete="stage === 'register' ? 'new-password' : 'current-password'" autocomplete="<?= $authStage === 'register' ? 'new-password' : 'current-password'; ?>" x-bind:disabled="stage === 'email'" x-bind:required="stage !== 'email'" x-bind:minlength="stage === 'register' ? 12 : null" maxlength="72" x-ref="password" <?= $authStage === 'email' ? 'disabled' : 'required'; ?> <?= $authStage === 'register' ? 'minlength="12"' : ''; ?>>
                <button type="button" class="icon-button" x-on:click="visible = !visible" x-bind:aria-label="visible ? 'Hide password' : 'Show password'" x-bind:aria-pressed="visible" aria-label="Show password" aria-pressed="false"><?= icon('eye'); ?></button>
            </div>
            <small x-bind:hidden="stage !== 'register'" <?= $authStage !== 'register' ? 'hidden' : ''; ?>>Use 12–72 characters.</small>
        </div>
        <div class="field" x-bind:hidden="stage !== 'register'" <?= $authStage !== 'register' ? 'hidden' : ''; ?>>
            <label for="password-confirmation">Confirm password</label><input id="password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="72" x-bind:disabled="stage !== 'register'" x-bind:required="stage === 'register'" <?= $authStage !== 'register' ? 'disabled' : 'required'; ?>>
        </div>
        <label class="check-label" x-bind:hidden="stage !== 'password'" <?= $authStage !== 'password' ? 'hidden' : ''; ?>><input type="checkbox" name="remember" value="1" x-bind:disabled="stage !== 'password'" <?= $authStage !== 'password' ? 'disabled' : ''; ?>>Keep me signed in for 30 days</label>
        <button class="btn btn-primary" type="submit" data-loading="Continuing…" x-bind:disabled="busy">Continue<?= icon('arrow-right'); ?></button>
    </form>
    <div class="auth-security"><?= icon('lock-keyhole'); ?>Private by design. Your next chapter, in your hands.</div>
</div>
<script nonce="<?= e(CSP_NONCE); ?>">
document.addEventListener('alpine:init', () => {
    Alpine.data('entryFlow', () => {
        let root;
        return {
            stage: 'email', busy: false, visible: false,
            init() { root = this.$el; this.stage = root.dataset.stage; },
            get heading() { return { email: 'Your next move starts here.', password: 'Welcome back.', register: 'Make yourself at home.' }[this.stage]; },
            get description() { return { email: 'One place for your search, your experience and what comes next.', password: 'Enter your password to continue to your workspace.', register: 'A few details, then your next chapter can begin.' }[this.stage]; },
            changeEmail() {
                if (this.busy) return;
                this.stage = 'email'; this.visible = false;
                this.$refs.password.value = '';
                root.querySelector('[name="password_confirmation"]').value = '';
                root.querySelector('.form-error').hidden = true;
                this.$nextTick(() => this.$refs.email.focus());
            },
            async submit(event) {
                if (this.busy) return;
                const form = event.currentTarget;
                const error = form.querySelector('.form-error');
                error.hidden = true;
                this.busy = true;
                try {
                    const data = new FormData(form);
                    data.set('_csrf', await this.$store.app.refreshCsrf());
                    const result = await this.$store.app.request(form.action, { data });
                    if (result.auth_stage) {
                        this.stage = result.auth_stage;
                        this.$refs.email.value = result.email;
                        this.$nextTick(() => (this.stage === 'register' ? this.$refs.name : this.$refs.password).focus());
                    } else this.$store.app.handleResult(result);
                } catch (failure) {
                    if (failure.data?.auth_stage === 'email') {
                        this.stage = 'email'; this.visible = false;
                        this.$refs.password.value = '';
                        this.$nextTick(() => this.$refs.email.focus());
                    }
                    error.textContent = failure.message;
                    error.hidden = false;
                } finally { this.busy = false; }
            },
        };
    });
});
</script>
