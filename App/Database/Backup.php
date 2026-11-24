<?php

declare(strict_types=1);

namespace App\Database;

use RuntimeException;

final class Backup
{
    public static function create(array $config, string $directory, string $binary): string
    {
        if (!is_dir($directory) || !is_writable($directory)) {
            throw new RuntimeException('Backup directory must already exist and be writable.');
        }
        $oldMask = umask(0077);
        $credentials = tempnam($directory, '.mysql-');
        $destination =
            $directory .
            '/before-migrate-' .
            gmdate('Ymd-His') .
            '-' .
            bin2hex(random_bytes(4)) .
            '.sql';
        $errorFile = $destination . '.error.log';
        try {
            $quote = static fn(string $value): string => '"' .
                str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], $value) .
                '"';
            $options = "[client]\n";
            if ($config['socket'] !== '') {
                $options .= "protocol=SOCKET\n";
            }
            foreach (['host', 'port', 'user', 'pass', 'socket'] as $key) {
                if ($config['socket'] !== '' && in_array($key, ['host', 'port'], true)) {
                    continue;
                }
                if ($key === 'socket' && $config[$key] === '') {
                    continue;
                }
                $options .=
                    ($key === 'pass' ? 'password' : $key) .
                    '=' .
                    $quote((string) $config[$key]) .
                    "\n";
            }
            if (file_put_contents($credentials, $options) === false) {
                throw new RuntimeException('Could not write private backup credentials.');
            }
            $process = proc_open(
                [
                    $binary,
                    '--defaults-file=' . $credentials,
                    '--single-transaction',
                    '--skip-lock-tables',
                    '--no-tablespaces',
                    '--hex-blob',
                    '--',
                    $config['name'],
                ],
                [
                    0 => ['file', '/dev/null', 'r'],
                    1 => ['file', $destination, 'w'],
                    2 => ['file', $errorFile, 'w'],
                ],
                $pipes,
            );
            if (
                !is_resource($process) ||
                proc_close($process) !== 0 ||
                filesize($destination) === 0
            ) {
                @unlink($destination);
                // Client errors can include connection details; never print credentials.
                throw new RuntimeException(
                    'Database backup failed; private diagnostic log: ' .
                        $errorFile .
                        '. Migration was not started.',
                );
            }
            @unlink($errorFile);
            return $destination;
        } finally {
            @unlink($credentials);
            umask($oldMask);
        }
    }
}
