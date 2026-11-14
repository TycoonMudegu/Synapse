<div class="page-heading">
    <div>
        <a class="text-link mb-2" href="<?= e(app_base_path()); ?>/documents"><?= icon('arrow-left'); ?>Document library</a>
        <h1 class="break-all"><?= e($document['name']); ?></h1>
        <p><?= e(label($document['kind'])); ?> · Version <?= e($document['version']); ?> · <?= e(short_date($document['created_at'])); ?></p>
    </div>
    <a class="btn btn-primary" href="<?= e(app_base_path()); ?>/documents/<?= e($document['id']); ?>/download">
        <?= icon('download'); ?>Download
    </a>
</div>
<div class="content-grid">
    <section class="panel">
        <?php if (str_starts_with($document['mime_type'], 'image/')): ?>
        <img
            src="<?= e(app_base_path()); ?>/documents/<?= e($document['id']); ?>/download?preview=1"
            class="document-preview"
            alt="<?= e($document['name']); ?>"
        />
        <?php elseif (
    $document['mime_type'] === 'application/pdf'
): ?>
        <iframe
            title="PDF preview of <?= e($document['name']); ?>"
            class="document-preview"
            src="<?= e(app_base_path()); ?>/documents/<?= e($document['id']); ?>/download?preview=1"
        ></iframe>
        <?php elseif (
    $document['extracted_text']
): ?>
        <div class="panel-body">
            <h2 class="mb-4">Text preview</h2>
            <div class="prose-text"><?= e($document['extracted_text']); ?></div>
        </div>
        <?php else:empty_state(
        'file-text',
        'This document is ready to download',
        'An inline preview is not available for this file.',
    );endif; ?>
    </section>
    <aside class="stack">
        <section class="panel">
            <div class="panel-header"><h2>Document details</h2></div>
            <div class="panel-body pt-0">
                <div class="definition-row">
                    <span>File size</span>
                    <strong><?= e(number_format((float) $document['byte_size'] / 1024)); ?> KB</strong>
                </div>
                <div class="definition-row">
                    <span>Text extraction</span>
                    <strong><?= trim($document['extracted_text'] ?? '') !== '' ? 'Available' : 'No text extracted'; ?></strong>
                </div>
                <p class="muted text-xs my-4">
                    Stored outside the public web directory. Access requires your authenticated
                    session.
                </p>
                <?php if (
    $document['parent_id']
): ?>
                <a class="text-link" href="<?= e(app_base_path()); ?>/documents/<?= e($document['parent_id']); ?>">
                    Previous version<?= icon('arrow-left'); ?>
                </a>
                <?php endif; ?>
            </div>
        </section>
        <form
            action="<?= e(app_base_path()); ?>/api/documents/<?= e($document['id']); ?>/rename"
            data-async
            class="panel panel-body form-stack"
        >
            <div class="form-error" hidden role="alert"></div>
            <div class="field">
                <label for="document-name">Document name</label>
                <input
                    id="document-name"
                    name="name"
                    value="<?= e($document['name']); ?>"
                    required
                    maxlength="250"
                />
            </div>
            <button class="btn">Rename document</button>
        </form>
        <?php if (
    in_array($document['kind'], ['master_cv', 'tailored_cv'], true)
): ?>
        <button class="btn btn-soft" data-action="<?= e(app_base_path()); ?>/api/documents/<?= e($document['id']); ?>/master">
            <?= icon('star'); ?>Use as master CV
        </button>
        <?php endif; ?>
        <button
            class="btn btn-danger"
            data-action="<?= e(app_base_path()); ?>/api/documents/<?= e($document['id']); ?>/delete"
            data-confirm="Permanently delete this private document? Documents attached to applications are protected."
        >
            <?= icon('trash-2'); ?>Delete document
        </button>
    </aside>
</div>
<?php if ($associations || $nextVersions || $snapshot): ?>
    <section class="panel mt-6">
        <div class="panel-header"><h2>Connections & versions</h2></div>
        <div class="panel-body form-stack">
            <?php foreach ($associations as $associatedJob): ?>
                <a class="text-link" href="<?= e(app_base_path()); ?>/jobs/<?= e($associatedJob['id']); ?>">
                    <?= e($associatedJob['title'] . ' · ' . $associatedJob['employer']); ?>
                    <?= icon('arrow-up-right'); ?>
                </a>
            <?php endforeach; ?>
            <?php foreach ($nextVersions as $version): ?>
                <a class="text-link" href="<?= e(app_base_path()); ?>/documents/<?= e($version['id']); ?>">
                    Version <?= e($version['version']); ?> · <?= e($version['name']); ?>
                    <?= icon('arrow-right'); ?>
                </a>
            <?php endforeach; ?>
            <?php if ($snapshot && isset($snapshot['profile'])): ?>
                <div class="heading-actions">
                    <?php foreach (['pdf', 'docx'] as $format): ?>
                        <form action="<?= e(app_base_path()); ?>/api/documents/<?= e($document['id']); ?>/export" data-async>
                            <input type="hidden" name="format" value="<?= e($format); ?>">
                            <button class="btn btn-soft"><?= icon('download'); ?>Export <?= e(strtoupper($format)); ?></button>
                        </form>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($snapshot['changes'])): ?>
                <h3>Proposed tailoring changes</h3>
                <p class="prose-text"><?= e(implode("\n", $snapshot['changes'])); ?></p>
                <h3>Original professional summary</h3>
                <p class="prose-text"><?= e($snapshot['original_summary'] ?? ''); ?></p>
                <h3>Tailored professional summary</h3>
                <p class="prose-text"><?= e($snapshot['profile']['summary'] ?? ''); ?></p>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>
