<section class="panel">
    <div class="panel-header">
        <h2>System health</h2>
        <span class="badge <?= $heartbeat && time() - $heartbeat < 120 ? 'badge-green' : 'badge-warm'; ?>"><?= $heartbeat && time() - $heartbeat < 120 ? 'Worker recently seen' : 'Worker not recently seen'; ?></span>
    </div>
    <div class="panel-body">
        <div class="form-grid">
            <div>
                <div class="definition-row">
                    <span>PHP runtime</span>
                    <strong><?= e(PHP_VERSION); ?></strong>
                </div>
                <div class="definition-row">
                    <span>Database connection</span>
                    <strong>Connected</strong>
                </div>
                <div class="definition-row">
                    <span>Timezone</span>
                    <strong>Africa/Nairobi</strong>
                </div>
            </div>
            <div>
                <div class="definition-row">
                    <span>Private storage</span>
                    <strong><?= is_writable(BASE_PATH . '/storage/private') ? 'Writable' : 'Needs attention'; ?></strong>
                </div>
                <div class="definition-row">
                    <span>Credential encryption</span>
                    <strong><?= strlen(base64_decode(\App\Core\Env::get('APP_KEY'), true) ?: '') === 32 ? 'Key configured' : 'Key missing / invalid'; ?></strong>
                </div>
                <div class="definition-row">
                    <span>Last worker heartbeat</span>
                    <strong><?= $heartbeat ? e(date('d M, H:i', $heartbeat)) . ' EAT' : 'Never'; ?></strong>
                </div>
            </div>
        </div>
        <pre class="code-block mt-5">
php scripts/worker.php
php scripts/schedule.php</pre>
        <p class="muted text-xs mt-4">
            Configure cron to run the scheduler every minute. Keep the worker running with a process
            supervisor. Detailed PHP errors are recorded privately in storage/logs/php.log without
            request bodies or credentials.
        </p>
    </div>
</section>
<section class="panel">
    <div class="panel-header"><h2>Background tasks</h2></div>
    <?php if (!$tasks):
    empty_state(
        'list-checks',
        'No background tasks yet',
        'Queued searches, analyses, document preparation and synchronisation appear here.',
    );
else:
     ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Task</th>
                    <th>Status</th>
                    <th>Attempts</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (
    $tasks
    as $task
): ?>
                <tr>
                    <td><?= e(label($task['type'])); ?></td>
                    <td><span class="badge"><?= e(label($task['status'])); ?></span></td>
                    <td><?= e($task['attempts']); ?></td>
                    <td><?= e($task['last_error'] ?: short_date($task['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
endif; ?>
</section>
<section class="panel">
    <div class="panel-header"><h2>Automation runs</h2></div>
    <?php if (!$runs):
    empty_state(
        'history',
        'A clear record of each run',
        'Once scheduled work executes, its real results and errors appear here.',
    );
else:
     ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Task</th>
                    <th>Started</th>
                    <th>Status</th>
                    <th>Records / sources</th>
                    <th>Errors</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (
    $runs
    as $run
): ?>
                <tr>
                    <td><?= e(label($run['task_type'])); ?></td>
                    <td class="whitespace-nowrap"><?= e(date('d M, H:i', strtotime($run['started_at']))); ?></td>
                    <td><span class="badge"><?= e(label($run['status'])); ?></span></td>
                    <td><?= e($run['records_processed']); ?> / <?= e($run['sources_checked']); ?></td>
                    <td><?= e($run['error_summary'] ?? 'None'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
endif; ?>
</section>
