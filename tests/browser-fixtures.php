<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Core\Database;
use App\Repositories\DocumentRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Services\Documents\Exporter;

if (($argv[1] ?? '') === 'setup') {
    ob_start();
    require BASE_PATH . '/scripts/migrate.php';
    ob_end_clean();
    $password = bin2hex(random_bytes(18));
    $email = 'browser-' . bin2hex(random_bytes(8)) . '@example.test';
    $id = (new UserRepository())->create('Browser Fixture', $email, $password);
    $profile = (new ProfileRepository())->get($id);
    $docId = (new Exporter())->cv($id, $profile, 'docx', 'Browser fixture CV');
    (new DocumentRepository())->addCv($id, $docId);
    $document = (new DocumentRepository())->get($id, $docId);
    echo json_encode(
        [
            'id' => $id,
            'email' => $email,
            'password' => $password,
            'document_id' => $docId,
            'path' => BASE_PATH . '/storage/private/' . $document['storage_path'],
        ],
        JSON_THROW_ON_ERROR,
    );
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
        $path = BASE_PATH . '/storage/private/' . $document['storage_path'];
        if (is_file($path)) {
            unlink($path);
        }
    }
    $delete = Database::connection()->prepare('DELETE FROM users WHERE id = ?');
    $delete->execute([$id]);
}
