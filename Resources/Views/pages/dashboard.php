<div class="page-heading">
    <div>
        <div class="eyebrow mb-2">A LITTLE PROGRESS, EVERY DAY</div>
        <h1><?= e($greeting); ?>, <?= e(explode(' ', $currentUser['name'])[0]); ?>.</h1>
        <p>Let's find your next opportunity. Here's where things stand.</p>
    </div>
    <div class="heading-actions">
        <button class="btn" data-action="/api/automation/search" data-loading="Queuing search…">
            <?= icon('refresh-cw'); ?>Search jobs
        </button>
        <a class="btn btn-primary" href="/jobs/add"><?= icon('plus'); ?>Add job</a>
    </div>
</div>
<div class="welcome-banner">
    <div class="welcome-copy">
        <div class="eyebrow">YOUR NEXT CHAPTER IS OUT THERE</div>
        <h2>Good opportunities. Thoughtful applications.</h2>
        <p>
            A little more focus, a little less busywork. Bring your experience, and let's put it in
            front of the right people.
        </p>
        <a class="text-link" href="<?= empty($profile['verified_at'] ) ? '/profile' : '/discover'; ?>"><?= empty($profile['verified_at']) ? 'Start with your career profile' : 'Explore your opportunities'; ?><?= icon('arrow-right'); ?></a>
    </div>
    <div class="orbit" aria-hidden="true"></div>
    <div class="banner-symbol" aria-hidden="true">
        <?= icon('sprout'); ?>
        <span class="spark">✧</span>
    </div>
</div>
<div class="stat-grid">
    <?php
    $cards = [
        [
            'Jobs discovered',
            $stats['jobs']['total'],
            'briefcase-business',
            $stats['jobs']['today'] . ' new today',
            false,
        ],
        [
            'High-match opportunities',
            $stats['high'],
            'sparkles',
            '70% match or higher · check eligibility',
            false,
        ],
        [
            'Awaiting your review',
            $stats['applications']['review'],
            'clipboard-check',
            'A final look before the next step',
            true,
        ],
        [
            'Applications submitted',
            $stats['applications']['submitted'],
            'send',
            'Confirmed submissions',
            false,
        ],
    ];
    foreach ($cards as [$name, $number, $iconName, $caption, $warm]): ?>
    <div class="stat-card">
        <div class="stat-top">
            <span><?= e($name); ?></span>
            <span class="stat-icon <?= $warm ? 'warm' : ''; ?>"><?= icon($iconName); ?></span>
        </div>
        <div class="stat-number"><?= e($number); ?></div>
        <div class="stat-caption"><?= e($caption); ?></div>
    </div>
    <?php endforeach;
    ?>
</div>
<div class="dashboard-grid">
    <div class="stack">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Recent matches</h2>
                    <p>A few possibilities worth a closer look.</p>
                </div>
                <a class="text-link" href="/jobs">View all<?= icon('arrow-up-right'); ?></a>
            </div>
            <?php if ($jobs === []): ?> <?php empty_state(
                    'compass',
                    'Your next opportunity starts here',
                    'Add a job you have your eye on, or connect a permitted source to start discovering.',
                    '/jobs/add',
                    'Add your first job',
                ); ?> <?php else:foreach ($jobs as $job): ?>
            <a class="job-row" href="/jobs/<?= e($job['id']); ?>">
                <span class="company-avatar"><?= e(initials($job['employer'])); ?></span>
                <div class="min-w-0">
                    <h3><?= e($job['title']); ?></h3>
                    <p><?= e($job['employer']); ?> · <?= e($job['location'] ?: 'Location not supplied'); ?> · <?= e(label($job['employment_type'])); ?></p>
                    <p><?= $job['salary_min'] !== null ? e($job['currency'] . ' ' . number_format((float) $job['salary_min'])) : 'Salary not supplied'; ?> · Closes <?= e(short_date($job['deadline'])); ?></p>
                </div>
                <span class="badge <?= $job['score'] !== null ? 'badge-blue' : ''; ?>"><?= $job[ 'score' ] !== null ? e($job['score']) . '% match' : 'Not analysed'; ?></span>
            </a>
            <?php endforeach;endif; ?>
            <div class="panel-footer">
                <span class="muted text-[10px]">
                    <?= icon('shield-check', 'inline w-3 h-3 mr-1'); ?>Match scores guide your search. Eligibility is checked
                    separately.
                </span>
            </div>
        </section>
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Application activity</h2>
                    <p>Keep your next moves in view.</p>
                </div>
                <a class="text-link" href="/applications">View all<?= icon('arrow-up-right'); ?></a>
            </div>
            <?php if ($applications === []): ?> <?php empty_state(
                    'send',
                    'A clear path from saved to submitted',
                    'When you prepare your first application, its progress will appear here.',
                ); ?> <?php else:foreach ($applications as $application): ?>
            <a class="job-row" href="/applications/<?= e($application['id']); ?>">
                <span class="company-avatar"><?= e(initials($application['employer'])); ?></span>
                <div>
                    <h3><?= e($application['title']); ?></h3>
                    <p><?= e($application['employer']); ?> · <?= e(short_date($application['updated_at'])); ?></p>
                </div>
                <span class="badge badge-blue"><?= e(label($application['status'])); ?></span>
            </a>
            <?php endforeach;endif; ?>
        </section>
        <section class="panel">
            <div class="mini-stats">
                <div>
                    <strong><?= e($stats['jobs']['today']); ?></strong>
                    <span>New jobs today</span>
                </div>
                <div>
                    <strong><?= e($stats['applications']['interviews']); ?></strong>
                    <span>Interviews scheduled</span>
                </div>
                <div>
                    <strong><?= e($stats['applications']['attention']); ?></strong>
                    <span>Need attention</span>
                </div>
            </div>
        </section>
    </div>
    <div class="stack">
        <section class="panel">
            <div class="panel-header">
                <div>
                    <h2>Make yourself at home</h2>
                    <p>A few steps to get things flowing.</p>
                </div>
                <?= icon('sparkles', 'text-[#ac967d] w-4 h-4'); ?>
            </div>
            <div class="checklist">
                <?php
                $steps = [
                    [
                        !empty($profile['verified_at']),
                        'Build your career profile',
                        'Your experience is the starting point.',
                        '/profile',
                    ],
                    [
                        count($documents) > 0,
                        'Bring your CV',
                        'Upload an existing PDF or DOCX.',
                        '/profile',
                    ],
                    [
                        count(array_filter($providers, fn($p) => $p['health'] === 'connected')) > 0,
                        'Connect your AI provider',
                        'Your model. Your budget.',
                        '/settings?tab=ai',
                    ],
                    [
                        count(
                            array_filter(
                                $sources,
                                fn($s) => $s['enabled'] && $s['health'] === 'connected',
                            ),
                        ) > 0,
                        'Choose your job sources',
                        'Discover through permitted feeds.',
                        '/settings?tab=discovery',
                    ],
                ];
                foreach ($steps as $index => [$done, $name, $description, $href]): ?>
                <a class="checklist-item" href="<?= e($href); ?>">
                    <span class="step-dot <?= $done ? 'done' : ''; ?>"><?= $done ? icon('check') : $index + 1; ?></span>
                    <div>
                        <strong><?= e($name); ?></strong>
                        <p><?= e($description); ?></p>
                    </div>
                    <?= icon('chevron-right'); ?>
                </a>
                <?php endforeach;
                ?>
            </div>
        </section>
        <section class="panel">
            <div class="panel-header">
                <h2>Upcoming deadlines</h2>
                <?= icon('calendar-clock', 'text-[#b09c83] w-4 h-4'); ?>
            </div>
            <?php if ($stats['deadlines'] === []): ?>
            <div class="px-[23px] pt-1 pb-6">
                <p class="text-[11px] text-[#98a099] leading-7">
                    A little breathing room.
                    <br />
                    No saved opportunities have an upcoming deadline.
                </p>
            </div>
            <?php else:foreach ($stats['deadlines'] as $job): ?>
            <a class="job-row" href="/jobs/<?= e($job['id']); ?>">
                <div>
                    <h3><?= e($job['title']); ?></h3>
                    <p><?= e($job['employer']); ?></p>
                </div>
                <span class="badge badge-warm"><?= e(short_date($job['deadline'])); ?></span>
            </a>
            <?php endforeach;endif; ?>
        </section>
        <section class="panel">
            <div class="panel-header">
                <h2>Automation</h2>
                <span class="badge <?= $automation[ 'paused' ] ? 'badge-warm' : ''; ?>">
                    <span class="status-dot <?= !$automation['paused'] && $latestRun ? 'green' : ''; ?>"></span>
                    <?= $automation['paused'] ? 'Paused' : ($latestRun ? 'Worker activity recorded' : 'Not started'); ?>
                </span>
            </div>
            <div class="automation-summary">
                <div class="definition-row">
                    <span>Application mode</span>
                    <strong><?= e(label($automation['mode'])); ?></strong>
                </div>
                <div class="definition-row">
                    <span>Last search</span>
                    <strong><?= $latestRun ? e(short_date($latestRun['started_at'])) : 'No runs yet'; ?></strong>
                </div>
                <div class="definition-row">
                    <span>Next search</span>
                    <strong><?= $automation[ 'discovery_enabled' ] && !$automation['paused'] ? ($latestRun ? e(date('d M, H:i', strtotime($latestRun['started_at']) + (int) $discovery['interval_minutes'] * 60)) . ' EAT' : 'On next scheduler run') : 'Scheduling disabled'; ?></strong>
                </div>
                <?php if (
                    $latestRun
                ): ?>
                <div class="definition-row">
                    <span>Sources / new jobs</span>
                    <strong><?= e($latestRun['sources_checked']); ?> / <?= e($latestRun['records_processed']); ?></strong>
                </div>
                <?php endif; ?>
                <div class="soft-note mt-4"><?= $latestRun && $latestRun['error_summary'] ? e($latestRun['error_summary']) : 'You stay in control. In Manual mode, JobFlow prepares applications and never sends them.'; ?></div>
                <a class="text-link mt-4" href="/settings?tab=automation">
                    Manage automation<?= icon('arrow-right'); ?>
                </a>
            </div>
        </section>
    </div>
</div>
<?php if ($activity): ?>
<section class="panel mt-6">
    <div class="panel-header">
        <h2>Latest activity</h2>
        <a href="/activity" class="text-link">Activity centre<?= icon('arrow-up-right'); ?></a>
    </div>
    <?php foreach (
    array_slice($activity, 0, 3)
    as $event
): ?>
    <div class="activity-row">
        <?= icon('circle-check', 'text-[#9baea4]'); ?>
        <div>
            <h3><?= e($event['description']); ?></h3>
            <small><?= e(date('d M, H:i', strtotime($event['created_at']))); ?> EAT</small>
        </div>
    </div>
    <?php endforeach; ?>
</section>
<?php endif; ?>
<div class="quick-actions">
    <span class="eyebrow">QUICK ACTIONS</span>
    <a class="quick-action" href="/jobs/add"><?= icon('plus'); ?>Add a job</a>
    <button class="quick-action" data-open-dialog="upload-dialog">
        <?= icon('upload'); ?>Upload a CV
    </button>
    <a class="quick-action" href="/applications?status=ready_for_review">
        <?= icon('clipboard-check'); ?>Review applications
    </a>
    <a class="quick-action" href="/settings?tab=automation"><?= icon('sliders-horizontal'); ?>Automation settings</a>
</div>
