<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bootstrap/App.php';

use App\Core\Database;
use App\Services\Documents\DocumentService;
use App\Services\Documents\DocumentStorage;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$apply = in_array('--apply', $argv, true);
$db = Database::connection();
if (
    (int) $db->query("SELECT GET_LOCK('jobflow:cloud-document-migration', 0)")->fetchColumn() !== 1
) {
    throw new RuntimeException('A document migration is already running.');
}
try {
    $rows = $db
        ->query("SELECT * FROM documents WHERE storage_driver = 'local' ORDER BY id")
        ->fetchAll();
    echo count($rows) . ' local document records to migrate.' . PHP_EOL;
    if (!$apply) {
        echo "Preview only. Pass --apply to copy and verify documents. Local copies are retained; a private manifest records them for separately approved cleanup.\n";
        exit(0);
    }
    $storage = new DocumentStorage();
    $manifestPath =
        BASE_PATH .
        '/storage/run/cloud-document-migration-' .
        date('YmdHis') .
        '-' .
        bin2hex(random_bytes(4)) .
        '.json';
    $manifest = [];
    $saveManifest = static function () use (&$manifest, $manifestPath): void {
        $temporary = $manifestPath . '.tmp';
        if (
            file_put_contents(
                $temporary,
                json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
                LOCK_EX,
            ) === false
        ) {
            throw new RuntimeException('Could not record the migration manifest.');
        }
        chmod($temporary, 0600);
        if (!rename($temporary, $manifestPath)) {
            throw new RuntimeException('Could not commit the migration manifest.');
        }
    };
    foreach ($rows as $document) {
        $path = (new DocumentService())->path($document);
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) !== (int) $document['byte_size']) {
            throw new RuntimeException(
                'Local document ' . $document['id'] . ' is unreadable or incomplete.',
            );
        }
        // put() verifies the returned object checksum before the database switch.
        $cloud = $storage->put(
            $bytes,
            (int) $document['user_id'],
            $document['mime_type'],
            pathinfo($path, PATHINFO_EXTENSION),
        );
        $manifest[] = [
            'document_id' => (int) $document['id'],
            'user_id' => (int) $document['user_id'],
            'local_storage_path' => $document['storage_path'],
            'cloud' => $cloud,
            'byte_size' => strlen($bytes),
            'md5' => base64_encode(md5($bytes, true)),
        ];
        $saveManifest();
        $statement = $db->prepare(
            "UPDATE documents SET storage_driver = 'gcs', storage_bucket = ?, storage_path = ?, storage_generation = ? WHERE id = ? AND storage_driver = 'local' AND storage_path = ?",
        );
        $statement->execute([
            $cloud['storage_bucket'],
            $cloud['storage_path'],
            $cloud['storage_generation'],
            $document['id'],
            $document['storage_path'],
        ]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException(
                'Document changed during migration; verified cloud copy retained for recovery.',
            );
        }
        echo 'Verified and migrated document ' .
            $document['id'] .
            '; local original retained.' .
            PHP_EOL;
    }
    if ($manifest) {
        echo 'Private migration manifest: ' . $manifestPath . PHP_EOL;
    }
} finally {
    $db->query("SELECT RELEASE_LOCK('jobflow:cloud-document-migration')");
}
