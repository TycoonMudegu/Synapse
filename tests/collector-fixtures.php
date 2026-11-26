<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Repositories\AccountRepository;
use App\Repositories\CatalogueRepository;
use App\Repositories\CollectorRepository;
use App\Repositories\UserRepository;
use App\Services\JobService;

if (($argv[1] ?? '') === 'setup') {
    ob_start();
    require BASE_PATH . '/scripts/migrate.php';
    ob_end_clean();
    $tag = bin2hex(random_bytes(7));
    $fixture = ['tag' => $tag, 'password' => bin2hex(random_bytes(20)), 'accounts' => []];
    foreach (['admin', 'user'] as $role) {
        $email = 'collector-browser-' . $role . '-' . $tag . '@example.test';
        $id = (new UserRepository())->create(
            'Collector browser fixture',
            $email,
            $fixture['password'],
        );
        (new AccountRepository())->complete($id);
        if ($role === 'admin') {
            Database::connection()
                ->prepare('UPDATE users SET is_admin = 1 WHERE id = ?')
                ->execute([$id]);
        }
        $fixture['accounts'][$role] = ['id' => $id, 'email' => $email];
    }
    $job = (new JobService())->normalise([
        'title' => 'Catalogue fixture ' . $tag,
        'employer' => 'Collector browser ' . $tag,
        'description' => 'Browser fixture only. <script>window.fixtureXss=true</script>',
        'location' => 'Nairobi',
        'original_url' => 'https://example.com/jobs/' . $tag,
    ]);
    $fixture['catalogue_id'] = Database::transaction(
        fn() => (new CatalogueRepository())->upsert($job, null, true),
    )['id'];
    $fixture['source_id'] = (int) (new CollectorRepository())->sources()[0]['id'];
    $fixture['run_id'] = (new CollectorRepository())->request(
        $fixture['accounts']['admin']['id'],
        $fixture['source_id'],
    );
    echo json_encode($fixture, JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'cleanup') {
    $tag = $argv[2] ?? '';
    if (!preg_match('/^[a-f0-9]{14}$/D', $tag)) {
        throw new RuntimeException('Invalid browser fixture identity.');
    }
    $db = Database::connection();
    $db->prepare(
        'DELETE FROM collector_runs WHERE requested_by IN (SELECT id FROM users WHERE email LIKE ?)',
    )->execute(['collector-browser-%-' . $tag . '@example.test']);
    $db->prepare(
        "DELETE FROM shared_jobs WHERE JSON_UNQUOTE(JSON_EXTRACT(job_json, '$.employer')) = ?",
    )->execute(['Collector browser ' . $tag]);
    $db->prepare('DELETE FROM users WHERE email LIKE ?')->execute([
        'collector-browser-%-' . $tag . '@example.test',
    ]);
}
