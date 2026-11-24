<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\HttpException;

final class ApplicationRepository extends Repository
{
    private const SELECT = 'SELECT a.*, j.title, j.employer, j.location, j.deadline, j.status AS job_status, j.application_email, j.email_subject, j.original_url, j.instructions, j.verified_at AS job_verified_at, m.score, m.eligibility FROM applications a JOIN job_listings j ON j.id = a.job_id LEFT JOIN match_results m ON m.job_id = a.job_id AND m.user_id = a.user_id';

    public function all(int $userId, array $filters = [], int $limit = 100): array
    {
        $where = ' WHERE a.user_id = ?';
        $parameters = [$userId];
        if (($filters['status'] ?? '') !== '') {
            $where .= ' AND a.status = ?';
            $parameters[] = $filters['status'];
        }
        if (($filters['q'] ?? '') !== '') {
            $where .= ' AND (j.title LIKE ? OR j.employer LIKE ?)';
            $parameters[] = '%' . $filters['q'] . '%';
            $parameters[] = '%' . $filters['q'] . '%';
        }
        $sort = match ($filters['sort'] ?? '') {
            'deadline' => 'j.deadline IS NULL, j.deadline, a.id DESC',
            'match' => 'm.score DESC, a.id',
            default => 'a.updated_at DESC, a.id DESC',
        };
        return $this->rows(
            self::SELECT . $where . ' ORDER BY ' . $sort . ' LIMIT ' . min(200, max(1, $limit)),
            $parameters,
        );
    }

    public function get(int $userId, int $id, bool $lock = false): array
    {
        return $this->one(
            self::SELECT . ' WHERE a.user_id = ? AND a.id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$userId, $id],
        ) ?? throw new HttpException('Application not found.', 404);
    }

    public function create(int $userId, array $job): int
    {
        $this->query(
            'INSERT INTO applications (user_id, job_id, method, subject) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)',
            [
                $userId,
                $job['id'],
                $job['application_method'],
                $job['email_subject'] ?: 'Application for ' . $job['title'],
            ],
        );
        return (int) $this->db->lastInsertId();
    }

    public function update(int $userId, int $id, array $changes): void
    {
        $allowed = [
            'status',
            'cv_document_id',
            'letter_document_id',
            'documents_verified_at',
            'subject',
            'email_body',
            'notes',
            'submitted_at',
            'approved_at',
            'interview_at',
            'confirmation',
            'provider_message_id',
            'provider_thread_id',
            'send_key',
            'last_error',
        ];
        $sets = [];
        $parameters = [];
        foreach ($changes as $field => $value) {
            if (!in_array($field, $allowed, true)) {
                throw new \LogicException('Unsupported application field.');
            }
            $sets[] = $field . ' = ?';
            $parameters[] = $value;
        }
        if ($sets !== []) {
            $this->query(
                'UPDATE applications SET revision = revision + 1, ' .
                    implode(', ', $sets) .
                    ' WHERE user_id = ? AND id = ?',
                [...$parameters, $userId, $id],
            );
        }
    }

    public function letter(int $userId, int $applicationId): ?array
    {
        return $this->one(
            'SELECT * FROM cover_letters WHERE user_id = ? AND application_id = ? ORDER BY version DESC LIMIT 1',
            [$userId, $applicationId],
        );
    }

    public function saveLetter(int $userId, int $applicationId, string $content, ?int $cvId): int
    {
        $this->get($userId, $applicationId);
        $version = (int) $this->query(
            'SELECT COALESCE(MAX(version), 0) + 1 FROM cover_letters WHERE user_id = ? AND application_id = ?',
            [$userId, $applicationId],
        )->fetchColumn();
        $this->query(
            'INSERT INTO cover_letters (user_id, application_id, cv_document_id, content, version) VALUES (?, ?, ?, ?, ?)',
            [$userId, $applicationId, $cvId, $content, $version],
        );
        return $version;
    }

    public function sentToday(int $userId): int
    {
        return (int) $this->query(
            "SELECT COUNT(*) FROM applications WHERE user_id = ? AND (submitted_at >= CURDATE() OR (status = 'submitting' AND updated_at >= CURDATE()))",
            [$userId],
        )->fetchColumn();
    }
}
