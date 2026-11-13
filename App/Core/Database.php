<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection === null) {
            $socket = Env::get('DB_SOCKET');
            $host =
                $socket !== ''
                    ? 'unix_socket=' . $socket
                    : 'host=' .
                        Env::get('DB_HOST', '127.0.0.1') .
                        ';port=' .
                        Env::get('DB_PORT', '3306');
            self::$connection = new PDO(
                'mysql:' .
                    $host .
                    ';dbname=' .
                    Env::get('DB_DATABASE', 'jobflow') .
                    ';charset=utf8mb4',
                Env::get('DB_USERNAME', 'jobflow'),
                Env::get('DB_PASSWORD'),
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ],
            );
            self::$connection->exec("SET time_zone = '+03:00'");
        }

        return self::$connection;
    }

    public static function transaction(callable $callback): mixed
    {
        $db = self::connection();
        $db->beginTransaction();
        try {
            $result = $callback($db);
            $db->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }
}
