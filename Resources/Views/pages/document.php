<div class="document-workspace" x-data="{ detailsOpen: false }" x-on:keydown.escape.window="detailsOpen = false">
<div class="page-heading">
    <div>
        <a class="text-link mb-2" href="<?= e(app_base_path()); ?>/documents"><?= icon('arrow-left'); ?>Document library</a>
        <h1 class="break-all"><?= e($document['name']); ?></h1>
        <p><?= e(label($document['kind'])); ?> · Version <?= e($document['version']); ?> · <?= e(short_date($document['created_at'])); ?></p>
    </div>
    <div class="heading-actions">
    <button type="button" class="btn" x-data="actionButton" x-on:click.prevent="run"
        data-action="<?= e(app_url('/api/documents/' . $document['id'] . '/favorite')); ?>"
        data-payload="<?= e(json_encode(['favorite' => empty($document['is_favorite']) ? 1 : 0])); ?>"
        aria-pressed="<?= empty($document['is_favorite']) ? 'false' : 'true'; ?>"
        data-loading="<?= !empty($document['archived_at']) ? 'Restoring document…' : 'Saving…'; ?>">
        <?= icon('star'); ?><?= empty($document['is_favorite']) ? (!empty($document['archived_at']) ? 'Favorite & restore' : 'Add to favorites') : 'Remove favorite'; ?>
    </button>
    <button type="button" class="btn document-details-toggle" x-on:click="detailsOpen = !detailsOpen" x-bind:aria-expanded="detailsOpen" aria-expanded="false" aria-controls="document-details"><?= icon('sliders-horizontal'); ?>Details</button>
    <?php if (!empty($document['archived_at'])): ?>
    <button type="button" class="btn" disabled><?= icon('download'); ?>In cold storage</button>
    <?php else: ?>
    <a class="btn btn-primary" href="<?= e(app_base_path()); ?>/documents/<?= e($document['id']); ?>/download">
        <?= icon('download'); ?>Download
    </a>
    <?php endif; ?>
    </div>
</div>
<div class="content-grid document-layout">
    <section class="panel document-reading-panel">
        <?php if (!empty($document['archived_at'])): ?>
        <div class="document-archived-preview"><?php empty_state('archive', 'This document is in cold storage', 'Your submitted application record is saved. Add this document to favorites to restore viewing and downloading.'); ?></div>
        <?php elseif (str_starts_with($document['mime_type'], 'image/')): ?>
        <img
            src="<?= e(app_base_path()); ?>/documents/<?= e($document['id']); ?>/download?preview=1"
            class="document-preview"
            alt="<?= e($document['name']); ?>"
        />
        <?php elseif (
    $document['mime_type'] === 'application/pdf'
): ?>
        <?php require BASE_PATH . '/Resources/Views/components/document-reader.php'; ?>
        <?php elseif (
    $document['extracted_text'] || $document['mime_type'] === \App\Services\Documents\RendererClient::DOCX
): ?>
        <div class="panel-body">
            <?php if ($document['mime_type'] === \App\Services\Documents\RendererClient::DOCX): ?><div class="soft-note mb-4">This text preview does not show your DOCX design. Download the original or create a PDF preview to review its layout.</div><button class="btn btn-soft mb-4" x-data="actionButton" x-on:click.prevent="run" data-action="<?= e(app_url('/api/documents/' . $document['id'] . '/preview')); ?>" data-loading="Creating PDF preview…"><?= icon('file-search'); ?>Create PDF preview</button><?php endif; ?>
            <?php if ($document['extracted_text']): ?>
                <h2 class="mb-4">Text preview</h2>
                <div class="prose-text"><?= e($document['extracted_text']); ?></div>
            <?php else: ?>
                <p class="muted">No readable text was extracted. Use the PDF preview to inspect the document.</p>
            <?php endif; ?>
        </div>
        <?php else:empty_state(
        'file-text',
        'This document is ready to download',
        'An inline preview is not available for this file.',
    );endif; ?>
    </section>
    <button type="button" class="document-details-overlay" x-bind:hidden="!detailsOpen" hidden x-on:click="detailsOpen = false" aria-label="Close document details"></button>
    <aside class="stack document-details" id="document-details" x-bind:class="{ 'is-open': detailsOpen }">
        <button type="button" class="btn document-details-toggle" x-on:click="detailsOpen = false"><?= icon('x'); ?>Close details</button>
        <section class="panel">
            <div class="panel-header"><h2>Document details</h2></div>
            <div class="panel-body pt-0">
                <div class="definition-row">
                    <span>Availability</span>
                    <strong><?= !empty($document['archived_at']) ? 'Cold storage' : (!empty($document['is_favorite']) ? 'Favorite' : 'Available'); ?></strong>
                </div>
                <div class="definition-row">
                    <span>File size</span>
                    <strong><?= e(number_format((float) $document['byte_size'] / 1024)); ?> KB</strong>
                </div>
                <div class="definition-row">
                    <span>Text extraction</span>
                    <strong><?= trim($document['extracted_text'] ?? '') !== '' ? 'Available' : 'No text extracted'; ?></strong>
                </div>
                <p class="muted text-xs my-4">
                    This document is private. Viewing and downloading require your account.
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
            x-data="asyncForm" x-on:submit.prevent="submit($event)"
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
    empty($document['archived_at']) && in_array($document['kind'], ['master_cv', 'tailored_cv'], true)
): ?>
        <button class="btn btn-soft" x-data="actionButton" x-on:click.prevent="run" data-action="<?= e(app_base_path()); ?>/api/documents/<?= e($document['id']); ?>/master">
            <?= icon('star'); ?>Use as master CV
        </button>
        <?php endif; ?>
        <button
            class="btn btn-danger"
            x-data="actionButton" x-on:click.prevent="run" data-action="<?= e(app_base_path()); ?>/api/documents/<?= e($document['id']); ?>/delete"
            data-confirm="Permanently delete this private document? Documents attached to applications are protected."
        >
            <?= icon('trash-2'); ?>Delete document
        </button>
<?php if (($document['processing_status'] ?? 'ready') === 'pending'): ?>
    <p class="soft-note mt-4">Your document is saved. Text extraction is running in the background; you can view or download it now.</p>
<?php elseif (($document['processing_status'] ?? 'ready') === 'failed'): ?>
    <p class="soft-note mt-4">We could not read this document. Check its format and upload a valid copy before using it in an application.</p>
<?php endif; ?>
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
<?php if (empty($document['archived_at']) && $snapshot && (isset($snapshot['profile']) || !empty($snapshot['source_cv_id']))): ?>
                <div class="heading-actions">
                    <?php foreach (['pdf', 'docx'] as $format): ?>
                        <form action="<?= e(app_base_path()); ?>/api/documents/<?= e($document['id']); ?>/export" x-data="asyncForm" x-on:submit.prevent="submit($event)">
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
    </aside>
</div>
</div>
