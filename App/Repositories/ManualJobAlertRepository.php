<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\HttpException;

final class ManualJobAlertRepository extends Repository
{
    public function candidates(int $userId, int $minimumMatch): array
    {
        return $this->rows(
            "SELECT j.id FROM job_listings j JOIN match_results m ON m.job_id = j.id AND m.user_id = j.user_id LEFT JOIN applications a ON a.job_id = j.id AND a.user_id = j.user_id LEFT JOIN manual_job_alerts n ON n.job_id = j.id AND n.user_id = j.user_id WHERE j.user_id = ? AND j.application_method <> 'email' AND j.status IN ('saved', 'shortlisted') AND (j.deadline IS NULL OR j.deadline >= CURDATE()) AND m.eligibility = 'eligible' AND m.score >= ? AND (a.id IS NULL OR (a.status IN ('draft', 'ready_for_review', 'approved', 'awaiting_external_action') AND a.submitted_at IS NULL AND a.send_key IS NULL)) AND (n.id IS NULL OR n.status = 'pending') ORDER BY m.score DESC, j.id LIMIT 50",
            [$userId, $minimumMatch],
        );
    }

    public function ensure(int $userId, int $jobId): int
    {
        // Scope the insert as well as reads; a caller cannot register another user's vacancy.
        $this->query(
            'INSERT INTO manual_job_alerts (user_id, job_id) SELECT user_id, id FROM job_listings WHERE user_id = ? AND id = ? ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(manual_job_alerts.id)',
            [$userId, $jobId],
        );
        return (int) ($this->forJob($userId, $jobId)['id'] ??
            throw new HttpException('Job not found.', 404));
    }

    public function get(int $userId, int $id, bool $lock = false): array
    {
        return $this->one(
            'SELECT * FROM manual_job_alerts WHERE user_id = ? AND id = ?' .
                ($lock ? ' FOR UPDATE' : ''),
            [$userId, $id],
        ) ?? throw new HttpException('Manual application alert not found.', 404);
    }

    public function forJob(int $userId, int $jobId): ?array
    {
        return $this->one('SELECT * FROM manual_job_alerts WHERE user_id = ? AND job_id = ?', [
            $userId,
            $jobId,
        ]);
    }

    public function application(int $userId, int $jobId): ?array
    {
        return $this->one('SELECT * FROM applications WHERE user_id = ? AND job_id = ?', [
            $userId,
            $jobId,
        ]);
    }

    public function reserve(int $userId, int $id, string $messageId): void
    {
        $this->query(
            "UPDATE manual_job_alerts SET status = 'sending', message_id = ?, attempted_at = NOW(), last_error = NULL WHERE user_id = ? AND id = ? AND status = 'pending'",
            [$messageId, $userId, $id],
        );
    }

    public function sent(int $userId, int $id): void
    {
        $this->query(
            "UPDATE manual_job_alerts SET status = 'sent', sent_at = NOW(), last_error = NULL WHERE user_id = ? AND id = ? AND status = 'sending'",
            [$userId, $id],
        );
    }

    public function failed(int $userId, int $id): void
    {
        $this->query(
            "UPDATE manual_job_alerts SET status = 'failed', last_error = 'Email delivery was not confirmed. Check SMTP settings and the sender mailbox before retrying.' WHERE user_id = ? AND id = ? AND status = 'sending'",
            [$userId, $id],
        );
    }
}
