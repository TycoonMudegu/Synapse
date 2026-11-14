<div class="page-heading">
    <div>
        <div class="eyebrow mb-2">THE LITTLE THINGS THAT MOVE YOU FORWARD</div>
        <h1>Activity centre</h1>
        <p>Your matches, applications and updates. A clear record of what actually happened.</p>
    </div>
</div>
<div class="content-grid">
    <section class="panel">
        <div class="panel-header">
            <h2>Notifications</h2>
            <span class="badge badge-blue"><?= count(array_filter($notifications, fn($item) => !$item['read_at'])); ?> unread</span>
        </div>
        <?php if (!$notifications):
     empty_state(
         'bell',
         'A quiet moment',
         'New matches, completed documents, replies and important updates will appear here.',
     );
 else:
     foreach ($notifications as $notification): ?>
        <article class="activity-row <?= !$notification[ 'read_at' ] ? 'unread' : ''; ?>">
            <?= icon($notification['type'] === 'error' ? 'circle-alert' : 'bell', 'text-[#8da9b9]'); ?>
            <div class="flex-1">
                <h3><a href="<?= e(app_url($notification['href'])); ?>"><?= e($notification['title']); ?></a></h3>
                <p><?= e($notification['body']); ?></p>
                <small><?= e(date('d M Y, H:i', strtotime($notification['created_at']))); ?> EAT</small>
            </div>
            <?php if (
     !$notification['read_at']
 ): ?>
            <button
                class="icon-button"
                data-action="<?= e(app_base_path()); ?>/api/notifications/<?= e($notification['id']); ?>/read"
                aria-label="Mark notification as read"
            >
                <?= icon('check'); ?>
            </button>
            <?php endif; ?>
        </article>
        <?php endforeach;
 endif; ?>
    </section>
    <section class="panel">
        <div class="panel-header"><h2>Workspace history</h2></div>
        <?php if (
    !$activity
):
    empty_state(
        'activity',
        'A fresh page',
        'Your workspace activity will appear as you make progress.',
    );
else:
    foreach ($activity as $event): ?>
        <div class="activity-row">
            <?= icon('circle-check', 'text-[#9bab9e]'); ?>
            <div>
                <h3><?= e($event['description']); ?></h3>
                <small><?= e(date('d M Y, H:i', strtotime($event['created_at']))); ?> EAT</small>
            </div>
        </div>
        <?php endforeach;
endif; ?>
    </section>
</div>
