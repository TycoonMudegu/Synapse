<div class="page-heading">
    <div>
        <div class="eyebrow mb-2">YOUR STORY, WELL ORGANISED</div>
        <h1>Document library</h1>
        <p>CVs, cover letters and the supporting details. Private, versioned and within reach.</p>
    </div>
    <button class="btn btn-primary" data-open-dialog="upload-dialog">
        <?= icon('upload'); ?>Upload document
    </button>
</div>
<?php if (!$documents): ?>
<section class="panel"><?php empty_state(
    'files',
    'A little space for your experience',
    'Upload your master CV, save a job poster, or create your first application documents.',
); ?></section>
<?php else: ?>
<div class="document-grid">
    <?php foreach (
    $documents
    as $document
): ?>
    <article class="document-card">
        <?= icon($document['kind'] === 'job_poster' ? 'image' : 'file-text'); ?>
        <h3><a href="/documents/<?= e($document['id']); ?>"><?= e($document['name']); ?></a></h3>
        <div class="flex flex-wrap gap-2">
            <span class="badge"><?= e(label($document['kind'])); ?></span>
            <span class="badge">v<?= e($document['version']); ?></span>
            <?php if (
    $document['is_master']
): ?>
            <span class="badge badge-green">Master CV</span>
            <?php endif; ?>
        </div>
        <p><?= e(number_format((float) $document['byte_size'] / 1024)); ?> KB · <?= e(short_date($document['created_at'])); ?></p>
        <div class="heading-actions">
            <a class="btn btn-sm" href="/documents/<?= e($document['id']); ?>">
                View details<?= icon('arrow-up-right'); ?>
            </a>
            <a
                class="icon-button"
                href="/documents/<?= e($document['id']); ?>/download"
                aria-label="Download <?= e($document['name']); ?>"
            >
                <?= icon('download'); ?>
            </a>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
