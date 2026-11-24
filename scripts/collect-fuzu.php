<?php

declare(strict_types=1);

require dirname(__DIR__) . '/Bootstrap/App.php';

use App\Core\HttpException;
use App\Services\Discovery\DiscoveryService;
use App\Services\Discovery\SourceCatalog;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$limit = filter_var($argv[1] ?? '2', FILTER_VALIDATE_INT);
if ($limit === false || $limit < 1 || $limit > 20) {
    fwrite(STDERR, "Usage: php scripts/collect-fuzu.php [1-20 vacancies; default 2]\n");
    exit(1);
}
try {
    $items = (new DiscoveryService())
        ->adapter('fuzu_public')
        ->collect(SourceCatalog::get('fuzu'), $limit);
    echo json_encode(
        array_column($items, 'structured'),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        ($exception instanceof HttpException
            ? $exception->getMessage()
            : 'Fuzu collection failed. Check the browser installation and configuration.') .
            PHP_EOL,
    );
    exit(1);
}
