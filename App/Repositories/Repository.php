<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

abstract class Repository
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = Database::connection();
    }

    public function lockUser(int $userId): void
    {
        $this->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
    }

    protected function query(string $sql, array $parameters = []): \PDOStatement
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }

    protected function one(string $sql, array $parameters = []): ?array
    {
        return $this->query($sql, $parameters)->fetch() ?: null;
    }

    protected function rows(string $sql, array $parameters = []): array
    {
        return $this->query($sql, $parameters)->fetchAll();
    }
}
