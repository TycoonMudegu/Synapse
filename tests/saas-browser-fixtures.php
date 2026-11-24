<?php

declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
use App\Core\Database;
use App\Repositories\UserRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\DocumentRepository;
use App\Services\Documents\Exporter;
if (($argv[1] ?? '') === 'setup') {
    ob_start();
    require BASE_PATH . '/scripts/migrate.php';
    ob_end_clean();
    Database::connection()->exec('DELETE FROM login_attempts');
    (new \App\Repositories\AccountRepository())->saveSite('job_sources', [
        'myjobmag' => ['enabled' => true, 'health' => 'connected'],
        'talent' => ['enabled' => true, 'health' => 'connected'],
    ]);
    $id = (new UserRepository())->create(
        'Browser CV Fixture',
        'browser-cv-' . bin2hex(random_bytes(8)) . '@example.test',
        bin2hex(random_bytes(20)),
    );
    $profile = (new ProfileRepository())->get($id);
    $profile['phone'] = '+254712345678';
    $profile['summary'] =
        'Finance professional with experience in accounting, budget planning, financial reporting and audit. Improved processes by 20% and managed 5 projects for internal teams. Focused on clear financial documentation and client service.';
    $profile['skills'] = ['Accounting', 'Finance', 'Audit', 'Budget'];
    $profile['employment'] = [
        [
            'position' => 'Accountant',
            'employer' => 'Fixture Employer',
            'start_date' => '2020',
            'end_date' => '2025',
            'achievements' => 'Improved reporting by 20%.',
        ],
    ];
    $profile['education'] = [
        [
            'qualification' => 'Bachelor of Commerce',
            'institution' => 'Fixture University',
            'completion_year' => '2019',
        ],
    ];
    $doc = (new Exporter())->cv($id, $profile, 'docx', 'Onboarding fixture CV');
    echo json_encode([
        'id' => $id,
        'path' =>
            BASE_PATH .
            '/storage/private/' .
            (new DocumentRepository())->get($id, $doc)['storage_path'],
    ]);
} elseif (($argv[1] ?? '') === 'lookup') {
    $email = $argv[2] ?? '';
    if (!str_starts_with($email, 'browser-saas-') || !str_ends_with($email, '@example.test')) {
        exit(1);
    }
    $user = (new UserRepository())->byEmail($email);
    echo json_encode($user ? ['id' => $user['id'], 'onboarded_at' => $user['onboarded_at']] : null);
}
