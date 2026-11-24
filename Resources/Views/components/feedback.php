<div class="toast-region" aria-live="polite" id="toasts">
    <template x-for="notice in $store.app.toasts" x-bind:key="notice.id">
        <div class="toast" x-bind:class="{ error: notice.error }" x-bind:role="notice.error ? 'alert' : 'status'">
            <span x-text="notice.message"></span>
            <button type="button" aria-label="Dismiss notification" x-on:click="$store.app.dismiss(notice.id)">×</button>
        </div>
    </template>
</div>
<dialog id="upgrade-dialog" aria-labelledby="upgrade-heading">
    <div class="panel-body form-stack">
        <h2 id="upgrade-heading">A little extra help with Plus.</h2>
        <p>Unlock CV improvements and automation for KSh 200/month or KSh 1,500 once. For other AI tools, you can also add your own provider key in Settings.</p>
        <a class="btn btn-primary" href="<?= e(app_url('/plans')); ?>">Explore Plus</a>
        <button class="btn" type="button" x-on:click="$store.app.closeDialog($el)">Maybe later</button>
    </div>
</dialog>
