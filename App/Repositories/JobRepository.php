<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\HttpException;

final class JobRepository extends Repository
{
    private const SELECT = 'SELECT j.*, m.score, m.eligibility, m.result_json, s.name AS source_name FROM job_listings j LEFT JOIN match_results m ON m.job_id = j.id AND m.user_id = j.user_id LEFT JOIN job_sources s ON s.id = j.source_id AND s.user_id = j.user_id';

    public function list(
        int $userId,
        array $filters = [],
        int $limit = 50,
        ?callable $matches = null,
    ): array {
        $where = ['j.user_id = ?'];
        $parameters = [$userId];
        if (($filters['q'] ?? '') !== '') {
            $where[] = '(j.title LIKE ? OR j.employer LIKE ? OR j.location LIKE ?)';
            $term = '%' . $filters['q'] . '%';
            array_push($parameters, $term, $term, $term);
        }
        if (($filters['status'] ?? '') !== '') {
            $where[] = 'j.status = ?';
            $parameters[] = $filters['status'];
        }
        if (($filters['match'] ?? '') === 'high') {
            $where[] = 'm.score >= 70';
        }
        $sort = ($filters['sort'] ?? '') === 'match' ? 'm.score DESC, j.id DESC' : 'j.id DESC';
        $limit = min(200, max(1, $limit));
        $statement = $this->query(
            self::SELECT .
                ' WHERE ' .
                implode(' AND ', $where) .
                ' ORDER BY ' .
                $sort .
                ($matches === null ? ' LIMIT ' . $limit : ''),
            $parameters,
        );
        $jobs = [];
        while ($job = $statement->fetch()) {
            if ($matches === null || $matches($job)) {
                $jobs[] = $job;
                if (count($jobs) >= $limit) {
                    break;
                }
            }
        }
        return $jobs;
    }

    public function get(int $userId, int $id): array
    {
        return $this->one(self::SELECT . ' WHERE j.user_id = ? AND j.id = ?', [$userId, $id]) ??
            throw new HttpException('Job not found.', 404);
    }

    public function awaitingAnalysis(int $userId): array
    {
        return $this->rows(
            "SELECT j.id FROM job_listings j LEFT JOIN match_results m ON m.job_id = j.id AND m.user_id = j.user_id WHERE j.user_id = ? AND m.job_id IS NULL AND j.status = 'saved' AND (j.deadline IS NULL OR j.deadline >= CURDATE()) AND NOT EXISTS (SELECT 1 FROM background_tasks t WHERE t.user_id = j.user_id AND t.type = 'analyse_job' AND CAST(JSON_UNQUOTE(JSON_EXTRACT(t.payload_json, '$.job_id')) AS UNSIGNED) = j.id AND (t.status IN ('pending', 'running') OR (t.status = 'failed' AND t.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)))) ORDER BY j.id DESC LIMIT 10",
            [$userId],
        );
    }

    public function emailOpportunities(int $userId): int
    {
        return (int) $this->query(
            "SELECT COUNT(*) FROM job_listings WHERE user_id = ? AND application_method = 'email' AND application_email IS NOT NULL AND application_email <> '' AND status <> 'expired' AND (deadline IS NULL OR deadline >= CURDATE())",
            [$userId],
        )->fetchColumn();
    }

    public function duplicateId(int $userId, array $normalised): ?int
    {
        $existing = $this->one(
            'SELECT id FROM job_listings WHERE user_id = ? AND (fingerprint = ? OR canonical_hash = ?) LIMIT 1',
            [$userId, $normalised['fingerprint'], $normalised['canonical_hash']],
        );
        return $existing ? (int) $existing['id'] : null;
    }

    public function insert(int $userId, array $data): array
    {
        $fields = [
            'title',
            'employer',
            'description',
            'requirements',
            'responsibilities',
            'qualifications',
            'location',
            'employment_type',
            'workplace',
            'salary_min',
            'salary_max',
            'currency',
            'deadline',
            'original_url',
            'canonical_hash',
            'fingerprint',
            'application_method',
            'application_email',
            'email_subject',
            'instructions',
            'verified_at',
            'source_id',
            'poster_document_id',
        ];
        try {
            $this->query(
                'INSERT INTO job_listings (user_id, ' .
                    implode(', ', $fields) .
                    ') VALUES (' .
                    implode(', ', array_fill(0, count($fields) + 1, '?')) .
                    ')',
                [$userId, ...array_map(fn(string $field) => $data[$field] ?? null, $fields)],
            );
            return ['id' => (int) $this->db->lastInsertId(), 'duplicate' => false];
        } catch (\PDOException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }
            $existing = $this->duplicateId($userId, $data);
            if ($existing === null) {
                throw $exception;
            }
            return ['id' => $existing, 'duplicate' => true];
        }
    }

    public function edit(int $userId, int $id, array $data): void
    {
        \App\Core\Database::transaction(function () use ($userId, $id, $data): void {
            $this->lockUser($userId);
            $application = $this->one(
                'SELECT id, status, submitted_at FROM applications WHERE user_id = ? AND job_id = ? FOR UPDATE',
                [$userId, $id],
            );
            if (
                $application &&
                ($application['submitted_at'] ||
                    $application['status'] === 'submitting' ||
                    $application['status'] === 'awaiting_external_action')
            ) {
                throw new HttpException(
                    'This job belongs to a submitted or unresolved application. Its original details are retained.',
                );
            }
            $this->get($userId, $id);
            $fields = [
                'title',
                'employer',
                'description',
                'requirements',
                'responsibilities',
                'qualifications',
                'location',
                'employment_type',
                'workplace',
                'salary_min',
                'salary_max',
                'currency',
                'deadline',
                'original_url',
                'canonical_hash',
                'fingerprint',
                'application_method',
                'application_email',
                'email_subject',
                'instructions',
                'verified_at',
            ];
            try {
                $this->query(
                    'UPDATE job_listings SET revision = revision + 1, ' .
                        implode(', ', array_map(fn($field) => $field . ' = ?', $fields)) .
                        ' WHERE user_id = ? AND id = ?',
                    [...array_map(fn($field) => $data[$field] ?? null, $fields), $userId, $id],
                );
            } catch (\PDOException $exception) {
                if (($exception->errorInfo[1] ?? null) === 1062) {
                    throw new HttpException(
                        'Another saved opportunity already has this URL or job fingerprint.',
                        409,
                    );
                }
                throw $exception;
            }
            $this->query('DELETE FROM match_results WHERE user_id = ? AND job_id = ?', [
                $userId,
                $id,
            ]);
            if ($application) {
                $this->query(
                    "UPDATE applications SET method = ?, documents_verified_at = NULL, approved_at = NULL, status = CASE WHEN status = 'approved' THEN 'ready_for_review' ELSE status END WHERE user_id = ? AND id = ?",
                    [$data['application_method'], $userId, $application['id']],
                );
            }
        });
    }

    public function status(int $userId, int $id, string $status): void
    {
        $this->get($userId, $id);
        $this->query('UPDATE job_listings SET status = ? WHERE user_id = ? AND id = ?', [
            $status,
            $userId,
            $id,
        ]);
    }

    public function verify(int $userId, int $id): void
    {
        $this->get($userId, $id);
        $this->query('UPDATE job_listings SET verified_at = NOW() WHERE user_id = ? AND id = ?', [
            $userId,
            $id,
        ]);
    }

    public function saveMatch(int $userId, int $jobId, array $result, string $profileUpdated): void
    {
        $this->get($userId, $jobId);
        $this->query(
            'INSERT INTO match_results (user_id, job_id, score, eligibility, result_json, profile_updated_at) VALUES (?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE score = VALUES(score), eligibility = VALUES(eligibility), result_json = VALUES(result_json), profile_updated_at = VALUES(profile_updated_at), created_at = NOW()',
            [
                $userId,
                $jobId,
                $result['score'],
                $result['eligibility'],
                json_encode($result, JSON_THROW_ON_ERROR),
                $profileUpdated,
            ],
        );
    }

    public function dashboard(int $userId): array
    {
        $jobs = $this->one(
            'SELECT COUNT(*) AS total, COALESCE(SUM(DATE(created_at) = CURDATE()), 0) AS today FROM job_listings WHERE user_id = ?',
            [$userId],
        );
        $apps = $this->one(
            "SELECT COUNT(*) AS total, COALESCE(SUM(status = 'ready_for_review'), 0) AS review, COALESCE(SUM(submitted_at IS NOT NULL), 0) AS submitted, COALESCE(SUM(status = 'interview' AND interview_at >= NOW()), 0) AS interviews, COALESCE(SUM(status IN ('failed', 'awaiting_external_action')), 0) AS attention FROM applications WHERE user_id = ?",
            [$userId],
        );
        $high = (int) $this->query(
            'SELECT COUNT(*) FROM match_results WHERE user_id = ? AND score >= 70',
            [$userId],
        )->fetchColumn();
        $deadlines = $this->rows(
            self::SELECT .
                " WHERE j.user_id = ? AND j.deadline >= CURDATE() AND j.status <> 'archived' ORDER BY j.deadline LIMIT 5",
            [$userId],
        );
        return [
            'jobs' => $jobs,
            'applications' => $apps,
            'high' => $high,
            'deadlines' => $deadlines,
        ];
    }

    public function discoveredToday(int $userId): int
    {
        return (int) $this->query(
            'SELECT COUNT(*) FROM job_listings WHERE user_id = ? AND source_id IS NOT NULL AND created_at >= CURDATE()',
            [$userId],
        )->fetchColumn();
    }

    public function expire(int $userId): int
    {
        return $this->query(
            "UPDATE job_listings SET status = 'expired' WHERE user_id = ? AND deadline < CURDATE() AND status NOT IN ('expired', 'archived')",
            [$userId],
        )->rowCount();
    }
}
