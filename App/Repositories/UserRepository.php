<?php

declare(strict_types=1);

namespace App\Repositories;

final class UserRepository extends Repository
{
    public function byId(int $id): ?array
    {
        return $this->one('SELECT id, name, email, created_at, onboarding_step, onboarded_at FROM users WHERE id = ?', [$id]);
    }

    public function byEmail(string $email): ?array
    {
        return $this->one('SELECT * FROM users WHERE email = ?', [mb_strtolower($email)]);
    }

    public function count(): int
    {
        return (int) $this->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    public function byGoogle(string $subject): ?array
    {
        return $this->one('SELECT * FROM users WHERE google_subject = ?', [$subject]);
    }

    public function googleSubject(int $id, string $subject): void
    {
        $this->query('UPDATE users SET google_subject = ? WHERE id = ?', [$subject, $id]);
    }

    public function create(string $name, string $email, string $password): int
    {
        $this->query('INSERT INTO users (name, email, password_hash) VALUES (?, ?, ?)', [
            $name,
            mb_strtolower($email),
            password_hash($password, PASSWORD_DEFAULT),
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->query('INSERT INTO user_profiles (user_id, full_name, email) VALUES (?, ?, ?)', [
            $id,
            $name,
            $email,
        ]);
        return $id;
    }

    public function password(int $userId, string $hash): void
    {
        $this->query('UPDATE users SET password_hash = ? WHERE id = ?', [$hash, $userId]);
    }

    public function attempts(string $key): int
    {
        return (int) $this->query(
            'SELECT COUNT(*) FROM login_attempts WHERE attempt_key = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)',
            [$key],
        )->fetchColumn();
    }

    public function recordAttempt(string $key): void
    {
        $this->query('INSERT INTO login_attempts (attempt_key) VALUES (?)', [$key]);
    }

    public function addSession(int $userId, string $hash, string $agent): void
    {
        $this->query(
            'INSERT INTO user_sessions (session_hash, user_id, user_agent) VALUES (?, ?, ?)',
            [$hash, $userId, mb_substr($agent, 0, 200)],
        );
    }

    public function validSession(int $userId, string $hash): bool
    {
        return (bool) $this->one(
            'SELECT session_hash FROM user_sessions WHERE session_hash = ? AND user_id = ? AND revoked_at IS NULL',
            [$hash, $userId],
        );
    }

    public function sessions(int $userId): array
    {
        return $this->rows(
            'SELECT session_hash, user_agent, created_at FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL ORDER BY created_at DESC',
            [$userId],
        );
    }

    public function revokeSessions(int $userId, ?string $except = null): void
    {
        $this->query(
            'UPDATE user_sessions SET revoked_at = NOW() WHERE user_id = ? AND session_hash <> ?',
            [$userId, $except ?? ''],
        );
        $this->query('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
    }

    public function revokeSession(string $hash): void
    {
        $this->query('UPDATE user_sessions SET revoked_at = NOW() WHERE session_hash = ?', [$hash]);
    }

    public function remember(int $userId, string $selector, string $hash): void
    {
        $this->query(
            'INSERT INTO remember_tokens (user_id, selector, token_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))',
            [$userId, $selector, $hash],
        );
    }

    public function consumeRemember(string $selector): ?array
    {
        $this->db->beginTransaction();
        try {
            $token = $this->one(
                'SELECT * FROM remember_tokens WHERE selector = ? AND expires_at > NOW() FOR UPDATE',
                [$selector],
            );
            $this->query('DELETE FROM remember_tokens WHERE selector = ?', [$selector]);
            $this->db->commit();
            return $token;
        } catch (\Throwable $exception) {
            $this->db->rollBack();
            throw $exception;
        }
    }
}
