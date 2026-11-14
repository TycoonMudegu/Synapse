<div class="page-heading">
    <div>
        <div class="eyebrow mb-2">EVERY NEXT STEP, IN ONE PLACE</div>
        <h1>Applications</h1>
        <p>From a thoughtful draft to a new beginning. Keep the whole journey in view.</p>
    </div>
    <a class="btn btn-primary" href="<?= e(app_base_path()); ?>/jobs"><?= icon('briefcase-business'); ?>Choose an opportunity</a>
</div>
<section class="panel">
    <div class="toolbar">
        <form method="get" class="filter-form" data-filter-form>
            <input
                type="search"
                name="q"
                value="<?= e($_GET['q'] ?? ''); ?>"
                placeholder="Search roles or employers…"
                aria-label="Search applications"
            />
            <select name="status" aria-label="Application status">
                <option value="">All statuses</option>
                <?php foreach (
    $statuses
    as $status
): ?>
                <option value="<?= e($status); ?>" <?= ($_GET['status'] ?? '') === $status ? 'selected' : ''; ?>><?= e(label($status)); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="sort" aria-label="Sort applications">
                <option value="updated">Recently updated</option>
                <option value="deadline" <?= ($_GET[ 'sort' ] ?? '') === 'deadline' ? 'selected' : ''; ?>>Deadline first</option>
            </select>
            <button class="btn"><?= icon('sliders-horizontal'); ?>Filter</button>
        </form>
    </div>
    <div data-filter-results>
        <?php if (!$applications):
    empty_state(
        'send',
        'Your next move starts with a saved job',
        'Choose an opportunity, prepare your documents, and give every application the attention it deserves.',
        '/jobs',
        'Explore my jobs',
    );
else:
     ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Opportunity</th>
                        <th>Status</th>
                        <th>Method</th>
                        <th>Deadline</th>
                        <th>Submitted</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (
    $applications
    as $application
): ?>
                    <tr>
                        <td>
                            <a href="<?= e(app_base_path()); ?>/applications/<?= e($application['id']); ?>">
                                <strong><?= e($application['title']); ?></strong>
                                <div class="text-[11px] mt-1"><?= e($application['employer']); ?></div>
                            </a>
                        </td>
                        <td><span class="badge <?= $application['status'] === 'submitted' ? 'badge-green' : 'badge-blue'; ?>"><?= e(label($application['status'])); ?></span></td>
                        <td><?= e(label($application['method'])); ?></td>
                        <td class="whitespace-nowrap"><?= e(short_date($application['deadline'])); ?></td>
                        <td class="whitespace-nowrap"><?= $application['submitted_at'] ? e(short_date($application['submitted_at'])) : 'Not submitted'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
endif; ?>
    </div>
</section>
