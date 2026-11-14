<?php

declare(strict_types=1);

namespace App\Repositories;

final class IntegrationRepository extends Repository
{
    public function connection(int $userId): ?array
    {
        return $this->one(
            "SELECT * FROM integration_connections WHERE user_id = ? AND provider = 'gmail'",
            [$userId],
        );
    }

    public function connect(int $userId, string $email, string $tokens, string $scopes): void
    {
        $this->query(
            "INSERT INTO integration_connections (user_id, provider, account_email, encrypted_tokens, scopes) VALUES (?, 'gmail', ?, ?, ?) ON DUPLICATE KEY UPDATE account_email = VALUES(account_email), encrypted_tokens = VALUES(encrypted_tokens), scopes = VALUES(scopes)",
            [$userId, $email, $tokens, $scopes],
        );
    }

    public function tokens(int $userId, string $encrypted): void
    {
        $this->query(
            "UPDATE integration_connections SET encrypted_tokens = ? WHERE user_id = ? AND provider = 'gmail'",
            [$encrypted, $userId],
        );
    }

    public function disconnect(int $userId): void
    {
        $this->query(
            "DELETE FROM integration_connections WHERE user_id = ? AND provider = 'gmail'",
            [$userId],
        );
    }

    public function syncTime(int $userId): void
    {
        $this->query(
            "UPDATE integration_connections SET last_sync_at = NOW() WHERE user_id = ? AND provider = 'gmail'",
            [$userId],
        );
    }

    public function inbox(int $userId, array $filters = []): array
    {
        $where = ' WHERE c.user_id = ?';
        $parameters = [$userId];
        if (!empty($filters['application_id'])) {
            $where .= ' AND c.application_id = ?';
            $parameters[] = (int) $filters['application_id'];
        }
        if (!empty($filters['type'])) {
            $where .= ' AND c.message_type = ?';
            $parameters[] = $filters['type'];
        }
        return $this->rows(
            'SELECT c.*, j.title AS job_title FROM application_correspondence c LEFT JOIN applications a ON a.id = c.application_id LEFT JOIN job_listings j ON j.id = a.job_id' .
                $where .
                ' ORDER BY received_at DESC LIMIT 100',
            $parameters,
        );
    }

    public function message(int $userId, int $id): ?array
    {
        return $this->one('SELECT * FROM application_correspondence WHERE user_id = ? AND id = ?', [
            $userId,
            $id,
        ]);
    }

    public function threads(int $userId): array
    {
        return $this->rows(
            'SELECT id, provider_thread_id FROM applications WHERE user_id = ? AND provider_thread_id IS NOT NULL ORDER BY updated_at DESC LIMIT 50',
            [$userId],
        );
    }

    public function messageId(int $userId, string $gmailId): int
    {
        return (int) ($this->one(
            'SELECT id FROM application_correspondence WHERE user_id = ? AND gmail_id = ?',
            [$userId, $gmailId],
        )['id'] ?? 0);
    }

    public function storeMessage(int $userId, array $message): bool
    {
        $statement = $this->query(
            'INSERT IGNORE INTO application_correspondence (user_id, application_id, gmail_id, thread_id, sender, subject, body, snippet, message_type, has_attachments, received_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $message['application_id'],
                $message['gmail_id'],
                $message['thread_id'],
                mb_substr($message['sender'], 0, 500),
                mb_substr($message['subject'], 0, 500),
                $message['body'],
                $message['snippet'],
                $message['message_type'],
                (int) $message['has_attachments'],
                $message['received_at'],
            ],
        );
        return $statement->rowCount() > 0;
    }
}
