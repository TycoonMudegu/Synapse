<div class="notice">
    <?= icon('sparkles'); ?>
    <div>
        <strong>Your provider. Your model. Your budget.</strong>
        <p>
            Use Qwen, DeepSeek, OpenAI, Gemini's compatibility endpoint or another OpenAI-compatible
            API. Enter the current base URL, model and prices from your provider. Keys are encrypted
            and never returned to the browser.
        </p>
    </div>
</div>
<section class="panel">
    <div class="panel-header"><h2>Configured providers</h2></div>
    <?php if (
    !$providers
):
    empty_state(
        'unplug',
        'Your AI connection starts here',
        'Add a provider below. No model, connection or billing is assumed.',
    );
else:
     ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Provider</th>
                    <th>Model</th>
                    <th>Connection</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (
    $providers
    as $provider
): ?>
                <tr>
                    <td><strong><?= e($provider['name']); ?></strong></td>
                    <td><?= e($provider['model']); ?></td>
                    <td><span class="badge <?= $provider['health'] === 'connected' ? 'badge-green' : 'badge-warm'; ?>"><?= e(label($provider['health'])); ?></span></td>
                    <td>
                        <div class="flex gap-2">
                            <a
                                href="<?= e(app_base_path()); ?>/settings?tab=ai&edit_provider=<?= e($provider['id']); ?>#provider-form"
                                class="btn btn-sm"
                            >
                                Edit
                            </a>
                            <button
                                class="btn btn-sm"
                                x-data="actionButton" x-on:click.prevent="run" data-action="<?= e(app_base_path()); ?>/api/providers/<?= e($provider['id']); ?>/test"
                                data-loading="Testing model…"
                            >
                                Test connection
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
endif; ?>
</section>
<?php
$editingProvider = null;
foreach ($providers as $candidate) {
    if ((int) $candidate['id'] === (int) ($_GET['edit_provider'] ?? 0)) {
        $editingProvider = $candidate;
        break;
    }
}
$providerOptions = $editingProvider ? json_decode($editingProvider['options_json'], true) : [];
?>
<section class="panel" id="provider-form">
    <div class="panel-header">
        <h2><?= $editingProvider ? 'Edit provider' : 'Connect an AI provider'; ?></h2>
        <span class="badge"><?= icon('lock-keyhole', 'w-3 h-3'); ?>Encrypted credentials</span>
    </div>
    <form action="<?= e(app_base_path()); ?>/api/providers" x-data="asyncForm" x-on:submit.prevent="submit($event)" class="panel-body">
        <div class="form-error" hidden role="alert"></div>
        <input type="hidden" name="id" value="<?= e($editingProvider['id'] ?? 0); ?>" />
        <div class="form-grid">
            <?php
$inputField('name', 'Provider name', $editingProvider['name'] ?? '');
$inputField(
    'endpoint',
    'OpenAI-compatible API base URL',
    $editingProvider['endpoint'] ?? '',
    'url',
    'The base URL before /chat/completions.',
);
?>
            <div class="field">
                <label for="provider-api-key">API key</label>
                <input
                    type="password"
                    name="api_key"
                    id="provider-api-key"
                    autocomplete="new-password"
                    placeholder="<?= $editingProvider ? 'Leave blank to keep the saved key' : 'Paste your API key'; ?>"
                    <?= $editingProvider ? '' : 'required'; ?>
                />
            </div>
            <?php
$inputField('model', 'Exact model identifier', $editingProvider['model'] ?? '');
$inputField(
    'temperature',
    'Temperature (optional)',
    $providerOptions['temperature'] ?? '',
    'number',
    'Leave blank if the model does not accept temperature.',
);
$inputField(
    'max_tokens',
    'Maximum output tokens',
    $providerOptions['max_tokens'] ?? 3000,
    'number',
);
$inputField('timeout', 'Request timeout (seconds)', $providerOptions['timeout'] ?? 60, 'number');
$inputField(
    'vision_token_reserve',
    'Vision input token reservation',
    $providerOptions['vision_token_reserve'] ?? 16000,
    'number',
);
$inputField(
    'input_per_million',
    'Current input price / 1M tokens (USD)',
    $providerOptions['input_per_million'] ?? '',
    'number',
);
$inputField(
    'output_per_million',
    'Current output price / 1M tokens (USD)',
    $providerOptions['output_per_million'] ?? '',
    'number',
);
?>
            <div class="field">
                <label for="response-format">JSON response mode</label>
                <select name="response_format" id="response-format">
                    <option value="json_object">JSON object + local schema validation</option>
                    <option value="json_schema" <?= ($providerOptions[ 'response_format' ] ?? '') === 'json_schema' ? 'selected' : ''; ?>>
                        Strict JSON schema (where supported)
                    </option>
                </select>
            </div>
            <div class="field">
                <label for="token-parameter">Output token parameter</label>
                <select name="token_parameter" id="token-parameter">
                    <option value="max_tokens">max_tokens</option>
                    <option value="max_completion_tokens" <?= ($providerOptions[ 'token_parameter' ] ?? '') === 'max_completion_tokens' ? 'selected' : ''; ?>>
                        max_completion_tokens
                    </option>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" data-loading="Saving encrypted provider…">
                Save provider
            </button>
        </div>
    </form>
</section>
<section class="panel">
    <div class="panel-header"><h2>Task assignments & budget</h2></div>
    <form action="<?= e(app_base_path()); ?>/api/settings" x-data="asyncForm" x-on:submit.prevent="submit($event)" class="panel-body">
        <input type="hidden" name="section" value="ai" />
        <div class="form-error" hidden role="alert"></div>
        <div class="form-grid">
            <?php
foreach (
    [
        'screening_provider' => 'Extraction & screening provider',
        'tailoring_provider' => 'CV tailoring provider',
        'letter_provider' => 'Cover letter provider',
        'fallback_provider' => 'Fallback provider (optional)',
    ]
    as $field => $name
): ?>
            <div class="field">
                <label for="assignment-<?= e($field); ?>"><?= e($name); ?></label>
                <select name="<?= e($field); ?>" id="assignment-<?= e($field); ?>">
                    <option value="0">Not selected</option>
                    <?php foreach (
    $providers
    as $provider
): ?>
                    <option value="<?= e($provider['id']); ?>" <?= (int) $settings[$field] === (int) $provider['id'] ? 'selected' : ''; ?>><?= e($provider['name'] . ' · ' . $provider['model']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endforeach;
$inputField(
    'monthly_limit_usd',
    'Monthly spending ceiling (USD)',
    $settings['monthly_limit_usd'],
    'number',
    'Estimated using your entered prices. Failed or unmetered requests retain their reservation.',
);
foreach ($settings['weights'] as $field => $weight) {
    $inputField(
        'weight_' . $field,
        ucfirst($field) . ' matching weight (%)',
        $weight,
        'number',
        'The four weights must total 100.',
    );
}
?>
        </div>
        <div class="form-actions"><button class="btn btn-primary">Save AI preferences</button></div>
    </form>
</section>
<section class="panel">
    <div class="panel-header">
        <h2>Usage this month</h2>
        <span class="badge">Estimated costs · USD</span>
    </div>
    <?php if (
    !$usage
):
    empty_state(
        'chart-no-axes-combined',
        'No AI requests this month',
        'Usage and estimated cost appear after your first request.',
    );
else:
     ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Task</th>
                    <th>Requests</th>
                    <th>Input / output tokens</th>
                    <th>Estimated / reserved</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (
    $usage
    as $row
): ?>
                <tr>
                    <td><?= e(label($row['task'])); ?></td>
                    <td><?= e($row['requests']); ?></td>
                    <td><?= e($row['input_tokens']); ?> / <?= e($row['output_tokens']); ?></td>
                    <td>$<?= e(number_format((float) $row['estimated_cost'], 6)); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
endif; ?>
</section>
