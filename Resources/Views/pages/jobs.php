<div class="page-heading">
    <div>
        <div class="eyebrow mb-2"><?= $active === 'discover' ? 'EXPLORE WHAT COMES NEXT' : 'YOUR OPPORTUNITIES, TOGETHER'; ?></div>
        <h1><?= e($title); ?></h1>
        <p><?= $active === 'discover' ? 'Thoughtful discovery. A better fit for your next move.' : 'Save the possibilities. Focus on the ones that feel right.'; ?></p>
    </div>
    <div class="heading-actions">
        <?php if (
    $active === 'discover'
): ?>
        <button class="btn" data-action="/api/automation/search" data-loading="Queuing discovery…">
            <?= icon('refresh-cw'); ?>Search now
        </button>
        <?php endif; ?>
        <a class="btn btn-primary" href="/jobs/add"><?= icon('plus'); ?>Add job</a>
    </div>
</div>
<?php if (
    $active === 'discover' &&
    !array_filter($sources, fn($source) => $source['enabled'] && $source['health'] === 'connected')
): ?>
<div class="notice">
    <?= icon('radio'); ?>
    <div>
        <strong>Choose where your opportunities come from.</strong>
        <p>
            Connect and test a permitted RSS feed or public JSON API to enable discovery.
            <a class="text-link" href="/settings?tab=discovery">Set up sources <?= icon('arrow-right'); ?></a>
        </p>
    </div>
</div>
<?php endif; ?>
<section class="panel">
    <div class="toolbar">
        <form class="filter-form" method="get" data-filter-form>
            <input
                type="search"
                name="q"
                placeholder="Search titles, employers or locations…"
                aria-label="Search jobs"
                value="<?= e($_GET['q'] ?? ''); ?>"
            />
            <select name="status" aria-label="Job status">
                <option value="">All statuses</option>
                <?php foreach (
    ['saved', 'shortlisted', 'archived', 'expired']
    as $status
): ?>
                <option value="<?= e($status); ?>" <?= ($_GET['status'] ?? '') === $status ? 'selected' : ''; ?>><?= e(label($status)); ?></option>
                <?php endforeach; ?>
            </select>
            <select name="sort" aria-label="Sort jobs">
                <option value="newest">Newest first</option>
                <option value="match" <?= ($_GET[ 'sort' ] ?? '') === 'match' ? 'selected' : ''; ?>>Best match</option>
            </select>
            <select name="match" aria-label="Match filter">
                <option value="">Any match</option>
                <option value="high" <?= ($_GET[ 'match' ] ?? '') === 'high' ? 'selected' : ''; ?>>70% and above</option>
            </select>
            <button class="btn" type="submit"><?= icon('sliders-horizontal'); ?>Filter</button>
        </form>
    </div>
    <div data-filter-results>
        <?php if ($jobs === []):
            empty_state(
                'briefcase-business',
                'Room for your next possibility',
                'No opportunities match this view yet. Add a job from a link, a poster, advertisement text or your own notes.',
                '/jobs/add',
                'Add an opportunity',
            );
        else:
             ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Opportunity</th>
                        <th>Location & type</th>
                        <th>Match</th>
                        <th>Deadline</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (
                $jobs
                as $job
            ): ?>
                    <tr>
                        <td>
                            <a href="/jobs/<?= e($job['id']); ?>">
                                <strong><?= e($job['title']); ?></strong>
                                <div class="muted text-[11px]"><?= e($job['employer']); ?></div>
                            </a>
                        </td>
                        <td>
                            <?= e($job['location'] ?: 'Not supplied'); ?>
                            <div class="text-[10px] mt-1"><?= e(label($job['employment_type'])); ?></div>
                        </td>
                        <td>
                            <span class="badge badge-blue"><?= $job['score'] !== null ? e($job['score']) . '%' : 'Not analysed'; ?></span>
                            <?php if ($job['eligibility']): ?>
                            <div class="text-[10px] mt-1"><?= e(label($job['eligibility'])); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="whitespace-nowrap"><?= e(short_date($job['deadline'])); ?></td>
                        <td><span class="badge"><?= e(label($job['status'])); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        endif; ?>
    </div>
</section>
<p class="muted text-[11px] mt-4">
    Showing up to 50 opportunities. Use search and filters to narrow your view.
</p>
