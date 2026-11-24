<div class="page-heading">
    <div>
        <div class="eyebrow mb-2">YOUR STORY, WELL ORGANISED</div>
        <h1>Document library</h1>
        <p>Favorite documents you want to keep available. Other application attachments move to cold storage three days after submission.</p>
    </div>
    <button class="btn btn-primary" x-on:click="$store.app.openDialog('upload-dialog')">
        <?= icon('upload'); ?>Upload document
    </button>
</div>
<nav class="heading-actions mb-4" aria-label="Document filters">
    <a class="btn btn-sm <?= empty($favoritesOnly) ? 'btn-soft' : ''; ?>" href="<?= e(app_url('/documents')); ?>" <?= empty($favoritesOnly) ? 'aria-current="page"' : ''; ?>>All documents</a>
    <a class="btn btn-sm <?= !empty($favoritesOnly) ? 'btn-soft' : ''; ?>" href="<?= e(app_url('/documents?filter=favorites')); ?>" <?= !empty($favoritesOnly) ? 'aria-current="page"' : ''; ?>><?= icon('star'); ?>Favorites</a>
</nav>
<?php if (!$documents): ?>
<section class="panel"><?php empty_state(
    'files',
    !empty($favoritesOnly) ? 'No favorites yet' : 'A little space for your experience',
    !empty($favoritesOnly) ? 'Add a document to favorites to keep it available here.' : 'Upload your master CV, save a job poster, or create your first application documents.',
); ?></section>
<?php else: ?>
<div class="document-grid">
    <?php foreach (
    $documents
    as $document
): ?>
    <article class="document-card <?= !empty($document['archived_at']) ? 'document-card-archived' : ''; ?>" data-document-id="<?= e($document['id']); ?>">
        <?= icon($document['kind'] === 'job_poster' ? 'image' : 'file-text'); ?>
        <h3><a href="<?= e(app_base_path()); ?>/documents/<?= e($document['id']); ?>"><?= e($document['name']); ?></a></h3>
        <div class="flex flex-wrap gap-2">
            <span class="badge"><?= e(label($document['kind'])); ?></span>
            <span class="badge">v<?= e($document['version']); ?></span>
            <?php if (!empty($document['is_favorite'])): ?><span class="badge badge-green">Favorite</span><?php endif; ?>
            <?php if (!empty($document['archived_at'])): ?><span class="badge"><?= icon('archive'); ?>Cold storage</span><?php endif; ?>
            <?php if (
    $document['is_master']
): ?>
            <span class="badge badge-green">Master CV</span>
            <?php endif; ?>
        </div>
        <p><?= e(number_format((float) $document['byte_size'] / 1024)); ?> KB · <?= e(short_date($document['created_at'])); ?></p>
        <?php if (!empty($document['archived_at'])): ?><p>Add to favorites to restore access.</p><?php endif; ?>
        <button type="button" class="btn btn-sm mt-4" x-data="actionButton" x-on:click.prevent="run"
            data-action="<?= e(app_url('/api/documents/' . $document['id'] . '/favorite')); ?>"
            data-payload="<?= e(json_encode(['favorite' => empty($document['is_favorite']) ? 1 : 0])); ?>"
            aria-pressed="<?= empty($document['is_favorite']) ? 'false' : 'true'; ?>"
            data-loading="<?= !empty($document['archived_at']) ? 'Restoring document…' : 'Saving…'; ?>">
            <?= icon('star'); ?><?= empty($document['is_favorite']) ? (!empty($document['archived_at']) ? 'Favorite & restore' : 'Add to favorites') : 'Remove favorite'; ?>
        </button>
        <div class="heading-actions">
            <a class="btn btn-sm" href="<?= e(app_base_path()); ?>/documents/<?= e($document['id']); ?>">
                View details<?= icon('arrow-up-right'); ?>
            </a>
            <?php if (!empty($document['archived_at'])): ?>
            <button type="button" class="icon-button" disabled aria-label="Download unavailable in cold storage" title="Add to favorites to restore access"><?= icon('download'); ?></button>
            <?php else: ?><a
                class="icon-button"
                href="<?= e(app_base_path()); ?>/documents/<?= e($document['id']); ?>/download"
                aria-label="Download <?= e($document['name']); ?>"
            >
                <?= icon('download'); ?>
            </a>
            <?php endif; ?>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
