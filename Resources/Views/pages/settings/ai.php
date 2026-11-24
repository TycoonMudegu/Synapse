<?php $aiMembership = (new \App\Repositories\AccountRepository())->membership(\App\Services\Security\Auth::id()); ?>
<section class="panel"><div class="panel-header"><div><h2>Your AI assistant</h2><p>Use your own provider key or get built-in AI with Synapse Plus.</p></div></div><div class="panel-body">
<?php if ($aiMembership['active']): ?><div class="notice"><?= icon('sparkles'); ?><div><strong>Built-in AI is included in your plan.</strong><p>You can use your own key instead if you prefer. Your provider bills usage separately.</p></div></div><?php else: require BASE_PATH . '/Resources/Views/components/upgrade.php'; endif; ?>
<?php if ($providers): ?><div class="soft-note">Saved connections: <?= e(implode(', ', array_column($providers, 'name'))); ?>.</div><?php endif; ?>
<details class="provider-details mt-5"><summary>Add or replace my AI key</summary>
<form action="<?= e(app_url('/api/ai/connect')); ?>" method="post" x-data="asyncForm" x-on:submit.prevent="submit($event)" class="form-stack">
    <?= csrf_field(); ?><div class="form-error" role="alert" hidden></div>
    <div class="field"><label for="simple-provider">AI provider</label><select id="simple-provider" name="provider" x-model="provider"><option value="gemini">Google Gemini</option><option value="compatible">Another compatible provider</option></select></div>
    <div class="field" x-bind:hidden="provider !== 'compatible'" hidden><label for="simple-endpoint">API base URL</label><input id="simple-endpoint" name="endpoint" x-bind:required="provider === 'compatible'" type="url"></div>
    <div class="field"><label for="simple-key">API key</label><input id="simple-key" name="api_key" type="password" autocomplete="off" required><small>Encrypted before storage and never displayed.</small></div>
    <div class="field"><label for="simple-model">Model name</label><input id="simple-model" name="model" maxlength="200" required></div>
    <p class="setup-note">For budget protection, enter the model’s current USD price per million tokens.</p><div class="form-grid"><div class="field"><label for="simple-input">Input price</label><input id="simple-input" name="input_per_million" type="number" min="0.001" step="0.001" required></div><div class="field"><label for="simple-output">Output price</label><input id="simple-output" name="output_per_million" type="number" min="0.001" step="0.001" required></div></div>
    <button class="btn btn-primary self-start" type="submit">Save my AI key</button>
</form></details></div></section>
<details class="panel mt-6" x-data="disclosure" x-on:toggle="save" data-persist-disclosure="ai"><summary class="panel-body">Advanced AI connections and usage</summary><?php require BASE_PATH . '/Resources/Views/pages/settings/ai-advanced.php'; ?></details>
