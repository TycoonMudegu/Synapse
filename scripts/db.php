<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}
require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database\Backup;
use App\Database\Runner;

try {
    $root = dirname(__DIR__);
    $command = $argv[1] ?? 'help';
    if (in_array($command, ['help', '--help'], true)) {
        echo "Usage: php scripts/db.php status|check|migrate [--env-file=/private/.env]\n";
        echo "       php scripts/db.php migrate --backup-dir=/private/backups [--retry-failed]\n";
        echo "       php scripts/db.php create DescriptiveName\n";
        echo "Status exit codes: 0 current, 3 pending, 1 error. No rollback or database copying.\n";
        exit(0);
    }
    if ($command === 'create') {
        $name = $argv[2] ?? '';
        if ($argc !== 3 || !preg_match('/^[A-Z][A-Za-z0-9]*$/D', $name)) {
            throw new RuntimeException('Supply one CamelCase migration name.');
        }
        $files = Runner::files($root);
        $last = (int) explode('_', array_key_last($files), 2)[0];
        $number = str_pad((string) ($last + 1), 3, '0', STR_PAD_LEFT);
        $slug = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
        $path = $root . '/database/migrations/' . $number . '_' . $slug . '.sql';
        $handle = fopen($path, 'x');
        if (!$handle) {
            throw new RuntimeException('Cannot create migration.');
        }
        fwrite(
            $handle,
            "-- Add reviewed, additive SQL here, then update database/schema.php.\n-- Never edit an already-applied migration.\n",
        );
        fclose($handle);
        echo basename($path), PHP_EOL;
        exit(0);
    }
    if (!in_array($command, ['status', 'check', 'migrate'], true)) {
        throw new RuntimeException('Unknown database command.');
    }
    $options = ['env-file' => $root . '/.env', 'dump-bin' => 'mysqldump'];
    $retry = false;
    foreach (array_slice($argv, 2) as $argument) {
        if ($argument === '--retry-failed') {
            $retry = true;
            continue;
        }
        if (!preg_match('/^--(env-file|backup-dir|dump-bin)=(.+)$/s', $argument, $match)) {
            throw new RuntimeException('Unknown database option.');
        }
        $options[$match[1]] = $match[2];
    }
    $connection = require $root . '/database/connection.php';
    $config = $connection($options['env-file']);
    $runner = new Runner($root, $config);
    if ($command === 'status') {
        $history = $runner->history();
        foreach (Runner::files($root) as $name => $path) {
            echo in_array($name, $history['applied'], true) ? 'APPLIED ' : 'PENDING ',
                $name,
                PHP_EOL;
        }
        exit($history['pending'] ? 3 : 0);
    }
    if ($command === 'check') {
        $runner->check();
        echo "Schema requirements satisfied.\n";
        exit(0);
    }
    $runner->locked(static function () use ($runner, $config, $options, $retry): void {
        $history = $runner->history();
        if ($history['pending'] && isset($options['backup-dir'])) {
            echo 'Backup: ',
                Backup::create($config, $options['backup-dir'], $options['dump-bin']),
                PHP_EOL;
        }
        do {
            $history = $runner->next($retry);
        } while ($history['pending']);
        echo "Database is up to date; release schema verified.\n";
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'Database command failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
