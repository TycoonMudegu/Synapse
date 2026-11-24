<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Repositories\DocumentRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Services\Documents\Exporter;

if (in_array($argv[1] ?? '', ['setup', 'setup-preview', 'setup-retention'], true)) {
    ob_start();
    require BASE_PATH . '/scripts/migrate.php';
    ob_end_clean();
    // Browser runs share loopback; reset only transient throttles in the guarded test database.
    Database::connection()->exec('DELETE FROM login_attempts');
    $password = bin2hex(random_bytes(18));
    $email = 'browser-' . bin2hex(random_bytes(8)) . '@example.test';
    $id = (new UserRepository())->create('Browser Fixture', $email, $password);
    (new \App\Repositories\AccountRepository())->complete($id);
    Database::connection()
        ->prepare("INSERT INTO memberships (user_id, plan) VALUES (?, 'lifetime')")
        ->execute([$id]);
    $profile = (new ProfileRepository())->get($id);
    $docId = (new Exporter())->cv($id, $profile, 'docx', 'Browser fixture CV');
    (new DocumentRepository())->addCv($id, $docId);
    $document = (new DocumentRepository())->get($id, $docId);
    $fixture = [
        'id' => $id,
        'email' => $email,
        'password' => $password,
        'document_id' => $docId,
        'path' => BASE_PATH . '/storage/private/' . $document['storage_path'],
    ];
    if ($argv[1] === 'setup-retention') {
        $archivedId = (new Exporter())->letter(
            $id,
            'Archived fixture.',
            'pdf',
            'Archived application letter',
        );
        Database::connection()
            ->prepare(
                "UPDATE documents SET archived_at = NOW(), storage_class = 'COLDLINE' WHERE user_id = ? AND id = ?",
            )
            ->execute([$id, $archivedId]);
        $fixture['archived_id'] = $archivedId;
    }
    if ($argv[1] === 'setup-preview') {
        $pdfId = (new Exporter())->cv($id, $profile, 'pdf', 'Preview fixture CV');
        $letterId = (new Exporter())->letter(
            $id,
            implode(
                "\n\n",
                array_fill(
                    0,
                    28,
                    'Private document preview browser fixture. This paragraph verifies continuous reading and page navigation.',
                ),
            ),
            'pdf',
            'Preview fixture letter',
        );
        $jobId = (new \App\Services\JobService())->create($id, [
            'title' => 'Preview fixture vacancy',
            'employer' => 'Preview fixture organisation',
            'description' => 'Browser test fixture, not a real vacancy.',
            'verified' => true,
        ])['id'];
        $applications = new \App\Repositories\ApplicationRepository();
        $applicationId = $applications->create(
            $id,
            (new \App\Repositories\JobRepository())->get($id, $jobId),
        );
        $applications->update($id, $applicationId, [
            'cv_document_id' => $pdfId,
            'letter_document_id' => $letterId,
        ]);
        $fixture += [
            'pdf_id' => $pdfId,
            'letter_id' => $letterId,
            'application_id' => $applicationId,
        ];
    }
    echo json_encode($fixture, JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'cleanup') {
    $id = (int) ($argv[2] ?? 0);
    $user = (new UserRepository())->byId($id);
    if (
        !$user ||
        !str_starts_with($user['email'], 'browser-') ||
        !str_ends_with($user['email'], '@example.test')
    ) {
        throw new RuntimeException('Refusing to remove a non-fixture account.');
    }
    foreach ((new DocumentRepository())->all($id) as $document) {
        if (\App\Services\Documents\DocumentStorage::isCloud($document)) {
            (new \App\Services\Documents\DocumentStorage())->delete($document);
            continue;
        }
        $path = BASE_PATH . '/storage/private/' . $document['storage_path'];
        if (is_file($path)) {
            unlink($path);
        }
    }
    $delete = Database::connection()->prepare('DELETE FROM users WHERE id = ?');
    $delete->execute([$id]);
}
