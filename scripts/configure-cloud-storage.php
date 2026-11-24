<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bootstrap/App.php';

use App\Core\Env;
use Google\Cloud\Storage\StorageClient;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$origins = ['http://localhost'];
$urls = [Env::get('APP_URL'), ...explode(',', Env::get('GCS_CORS_ORIGINS'))];
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--origin=')) {
        $urls[] = substr($argument, 9);
    }
}
foreach ($urls as $url) {
    if ($url === '') {
        continue;
    }
    $parts = parse_url($url);
    if (
        !$parts ||
        !in_array($parts['scheme'] ?? '', ['http', 'https'], true) ||
        empty($parts['host']) ||
        isset($parts['user']) ||
        isset($parts['pass'])
    ) {
        throw new RuntimeException('Invalid CORS origin.');
    }
    $origins[] =
        $parts['scheme'] .
        '://' .
        $parts['host'] .
        (isset($parts['port']) ? ':' . $parts['port'] : '');
}
$origins = array_values(array_unique($origins));
$client = new StorageClient([
    'projectId' => Env::get('GOOGLE_CLOUD_PROJECT'),
    'keyFilePath' => Env::get('GOOGLE_APPLICATION_CREDENTIALS'),
]);
$bucket = $client->bucket(Env::get('GCS_BUCKET'));
$info = $bucket->info();
if (
    empty($info['iamConfiguration']['uniformBucketLevelAccess']['enabled']) ||
    ($info['iamConfiguration']['publicAccessPrevention'] ?? '') !== 'enforced'
) {
    throw new RuntimeException(
        'Use a private bucket with uniform access and public access prevention enforced.',
    );
}
$cors = [
    [
        'origin' => $origins,
        'method' => ['GET', 'HEAD', 'PUT'],
        'responseHeader' => [
            'Content-Type',
            'Content-Length',
            'Content-Disposition',
            'ETag',
            'x-goog-if-generation-match',
        ],
        'maxAgeSeconds' => 3600,
    ],
];
echo json_encode(
    ['bucket' => $bucket->name(), 'cors' => $cors],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
) . PHP_EOL;
if (in_array('--apply', $argv, true)) {
    $bucket->update(['cors' => $cors], ['ifMetagenerationMatch' => $info['metageneration']]);
    echo "Private bucket CORS updated.\n";
} else {
    echo "Preview only. Pass --apply to configure browser access.\n";
}
