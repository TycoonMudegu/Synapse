<?php

declare(strict_types=1);

namespace App\Repositories;

final class AccountRepository extends Repository
{
    public function preferences(int $userId): array
    {
        return $this->one('SELECT * FROM onboarding_preferences WHERE user_id = ?', [$userId]) ?? [
            'user_id' => $userId,
            'field_key' => 'technology',
            'role_keywords' => '',
            'location' => 'Kenya',
            'source_keys' => '[]',
            'cv_document_id' => null,
            'letter_document_id' => null,
            'initial_grade_used' => false,
            'automatic_requested' => false,
        ];
    }

    public function savePreferences(int $userId, array $changes): void
    {
        $this->query('INSERT IGNORE INTO onboarding_preferences (user_id) VALUES (?)', [$userId]);
        foreach ($changes as $key => $value) {
            if (
                !in_array(
                    $key,
                    [
                        'field_key',
                        'role_keywords',
                        'location',
                        'source_keys',
                        'cv_document_id',
                        'letter_document_id',
                        'initial_grade_used',
                        'automatic_requested',
                    ],
                    true,
                )
            ) {
                throw new \InvalidArgumentException('Unknown account preference.');
            }
            $this->query('UPDATE onboarding_preferences SET ' . $key . ' = ? WHERE user_id = ?', [
                $value,
                $userId,
            ]);
        }
    }

    public function step(int $userId, int $step): void
    {
        $this->query(
            'UPDATE users SET onboarding_step = GREATEST(onboarding_step, ?) WHERE id = ?',
            [$step, $userId],
        );
    }

    public function complete(int $userId): void
    {
        $this->query(
            'UPDATE users SET onboarded_at = COALESCE(onboarded_at, NOW()), onboarding_step = 6 WHERE id = ?',
            [$userId],
        );
    }

    public function membership(int $userId, bool $lock = false): array
    {
        $row = $this->one('SELECT * FROM memberships WHERE user_id = ?' . ($lock ? ' FOR UPDATE' : ''), [$userId]);
        $active =
            $row &&
            ($row['plan'] === 'lifetime' ||
                ($row['plan'] === 'monthly' && strtotime($row['expires_at'] ?? '') > time()));
        return [
            'active' => (bool) $active,
            'plan' => $active ? $row['plan'] : 'free',
            'expires_at' => $row['expires_at'] ?? null,
        ];
    }

    public function site(string $key, array $default = []): array
    {
        $row = $this->one('SELECT value_json FROM site_settings WHERE setting_key = ?', [$key]);
        return $row ? json_decode($row['value_json'], true, 64, JSON_THROW_ON_ERROR) : $default;
    }

    public function saveSite(string $key, array $value): void
    {
        $this->query(
            'INSERT INTO site_settings (setting_key, value_json) VALUES (?, ?) ON DUPLICATE KEY UPDATE value_json = VALUES(value_json)',
            [$key, json_encode($value, JSON_THROW_ON_ERROR)],
        );
    }

    public function review(int $userId, int $documentId, string $field): ?array
    {
        return $this->one(
            'SELECT * FROM cv_reviews WHERE user_id = ? AND document_id = ? AND field_key = ?',
            [$userId, $documentId, $field],
        );
    }

    public function latestReview(int $userId): ?array
    {
        return $this->one(
            'SELECT r.*, d.name AS document_name FROM cv_reviews r JOIN documents d ON d.id = r.document_id AND d.user_id = r.user_id WHERE r.user_id = ? ORDER BY r.id DESC LIMIT 1',
            [$userId],
        );
    }

    public function saveReview(int $userId, int $documentId, string $field, array $result): void
    {
        $this->query(
            'INSERT INTO cv_reviews (user_id, document_id, field_key, score, result_json) VALUES (?, ?, ?, ?, ?)',
            [
                $userId,
                $documentId,
                $field,
                $result['score'],
                json_encode($result, JSON_THROW_ON_ERROR),
            ],
        );
    }

    public function saveAdvice(int $userId, int $id, array $advice): void
    {
        $this->query('UPDATE cv_reviews SET advice_json = ? WHERE user_id = ? AND id = ?', [
            json_encode($advice, JSON_THROW_ON_ERROR),
            $userId,
            $id,
        ]);
    }
}
