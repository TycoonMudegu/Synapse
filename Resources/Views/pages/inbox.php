<div class="page-heading">
    <div>
        <div class="eyebrow mb-2">KEEP THE CONVERSATION MOVING</div>
        <h1>Application inbox</h1>
        <p>Job alerts and application replies. Just the correspondence that belongs here.</p>
    </div>
    <div class="heading-actions">
        <?php if (
    $connection
): ?>
        <span class="text-xs muted">Last sync: <?= $connection['last_sync_at'] ? e(date('d M, H:i', strtotime($connection['last_sync_at']))) . ' EAT' : 'Never'; ?></span>
        <button class="btn" data-action="<?= e(app_base_path()); ?>/api/gmail/sync" data-loading="Queuing synchronisation…">
            <?= icon('refresh-cw'); ?>Synchronise
        </button>
        <?php else: ?>
        <a class="btn btn-primary" href="<?= e(app_base_path()); ?>/settings?tab=gmail"><?= icon('mail'); ?>Connect Gmail</a>
        <?php endif; ?>
    </div>
</div>
<?php if (!$connection): ?>
<section class="panel"><?php empty_state(
    'mail-open',
    'Bring the relevant conversations together',
    'Connect Gmail and opt into read-only monitoring. JobFlow keeps related replies and your configured job alerts here.',
    '/settings?tab=gmail',
    'Gmail settings',
); ?></section>
<?php else: ?>
<section class="panel">
    <div class="toolbar">
        <form class="filter-form" method="get">
            <select name="application_id" aria-label="Filter by application">
                <option value="">All applications</option>
                <?php foreach (
    $applications
    as $application
): ?>
                <option value="<?= e($application['id']); ?>" <?= (int) ($_GET['application_id'] ?? 0) === (int) $application['id'] ? 'selected' : ''; ?>><?= e($application['title'] . ' · ' . $application['employer']); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="type" aria-label="Message type">
                <option value="">All message types</option>
                <?php foreach (
    ['job_alert', 'response', 'interview_needs_review']
    as $type
): ?>
                <option value="<?= e($type); ?>" <?= ($_GET['type'] ?? '') === $type ? 'selected' : ''; ?>><?= e(label($type)); ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn">Filter</button>
        </form>
    </div>
    <?php if (!$messages):
    empty_state(
        'inbox',
        'All caught up',
        'Synchronise to bring in relevant alerts and replies. Enable monitoring and a specific search query in Gmail settings.',
    );
else:
     ?>
    <div class="inbox-layout">
        <div class="message-list">
            <?php foreach (
    $messages
    as $item
): ?>
            <a class="message-link" href="<?= e(app_base_path()); ?>/inbox?message=<?= e($item['id']); ?>">
                <span class="badge <?= $item['message_type'] === 'interview_needs_review' ? 'badge-warm' : ''; ?>"><?= e(label($item['message_type'])); ?></span>
                <h3 class="mt-2"><?= e($item['subject']); ?><?= $item['has_attachments'] ? icon('paperclip', 'inline w-3 h-3') : ''; ?></h3>
                <p><?= e(mb_substr($item['snippet'], 0, 95)); ?></p>
                <small><?= e(short_date($item['received_at'])); ?></small>
            </a>
            <?php endforeach; ?>
        </div>
        <div class="panel-body">
            <?php if (
    $message
): ?>
            <span class="eyebrow">From <?= e($message['sender']); ?></span>
            <h2 class="mt-3 mb-2"><?= e($message['subject']); ?></h2>
            <p class="muted text-xs mb-6"><?= e(date('d M Y, H:i', strtotime($message['received_at']))); ?> EAT</p>
            <?php if (
     $message['application_id']
 ): ?>
            <a class="text-link mb-5" href="<?= e(app_base_path()); ?>/applications/<?= e($message['application_id']); ?>">
                Related application<?= icon('arrow-up-right'); ?>
            </a>
            <?php endif; ?>
            <div class="prose-text"><?= e($message['body']); ?></div>
            <?php else:empty_state(
        'mail-open',
        'Choose a conversation',
        'Open a message to read it and review its associated application.',
    );endif; ?>
        </div>
    </div>
    <?php
endif; ?>
</section>
<?php endif; ?>
