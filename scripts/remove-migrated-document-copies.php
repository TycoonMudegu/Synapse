<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bootstrap/App.php';

use App\Core\Database;
use App\Services\Documents\DocumentStorage;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$manifest = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--manifest=')) {
        $manifest = substr($argument, 11);
    }
}
$manifest = realpath($manifest);
$root = realpath(BASE_PATH . '/storage/run');
if (
    !$manifest ||
    !$root ||
    !str_starts_with($manifest, $root . '/') ||
    !preg_match('/^cloud-document-migration-[a-zA-Z0-9-]+\.json$/', basename($manifest))
) {
    throw new RuntimeException('Choose a private cloud migration manifest.');
}
$entries = json_decode(file_get_contents($manifest), true, 64, JSON_THROW_ON_ERROR);
$apply = in_array('--apply', $argv, true);
$storage = new DocumentStorage();
$count = 0;
foreach ($entries as $entry) {
    $query = Database::connection()->prepare(
        'SELECT * FROM documents WHERE id = ? AND user_id = ?',
    );
    $query->execute([$entry['document_id'], $entry['user_id']]);
    $document = $query->fetch();
    $cloud = $entry['cloud'];
    if (
        !$document ||
        !DocumentStorage::isCloud($document) ||
        $document['storage_path'] !== $cloud['storage_path'] ||
        $document['storage_bucket'] !== $cloud['storage_bucket'] ||
        $document['storage_generation'] !== $cloud['storage_generation']
    ) {
        throw new RuntimeException('A document reference changed; cleanup stopped.');
    }
    if (!preg_match('/^[a-f0-9]{48}\.[a-z0-9]+$/', $entry['local_storage_path'])) {
        throw new RuntimeException('Invalid local document reference.');
    }
    $path = BASE_PATH . '/storage/private/' . $entry['local_storage_path'];
    if (!is_file($path)) {
        continue;
    }
    if (
        !hash_equals($entry['md5'], base64_encode(md5_file($path, true))) ||
        filesize($path) !== (int) $entry['byte_size']
    ) {
        throw new RuntimeException('A local document changed; cleanup stopped.');
    }
    $info = $storage->remote(
        fn() => $storage
            ->object($cloud['storage_path'], $cloud['storage_bucket'], $cloud['storage_generation'])
            ->info(),
    );
    if (
        !hash_equals($entry['md5'], $info['md5Hash'] ?? '') ||
        (int) $info['size'] !== (int) $entry['byte_size']
    ) {
        throw new RuntimeException('A cloud checksum changed; cleanup stopped.');
    }
    if ($apply && !unlink($path)) {
        throw new RuntimeException('Local cleanup failed for document ' . $entry['document_id']);
    }
    $count++;
}
echo $count .
    ($apply
        ? ' verified local copies removed.'
        : ' verified local copies ready for removal. Preview only; --apply deletes them.') .
    PHP_EOL;
