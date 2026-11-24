<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Repositories\ActivityRepository;
use App\Repositories\ApplicationRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\IntegrationRepository;
use App\Repositories\SettingsRepository;

final class ApplicationService
{
    public const TRANSITIONS = [
        'test_sent' => [],
        'draft' => ['ready_for_review', 'withdrawn'],
        'ready_for_review' => ['draft', 'approved', 'withdrawn'],
        'approved' => ['ready_for_review', 'awaiting_external_action', 'withdrawn'],
        'submitting' => [],
        'submitted' => ['interview', 'offer', 'rejected', 'withdrawn'],
        'awaiting_external_action' => ['submitted', 'interview', 'offer', 'rejected', 'withdrawn'],
        'interview' => ['offer', 'rejected', 'withdrawn'],
        'offer' => ['withdrawn'],
        'rejected' => [],
        'withdrawn' => [],
        'failed' => ['ready_for_review', 'withdrawn'],
    ];

    public function transition(int $userId, int $id, string $next, string $confirmation = ''): void
    {
        Database::transaction(function () use ($userId, $id, $next, $confirmation): void {
            $repository = new ApplicationRepository();
            $repository->lockUser($userId);
            $application = $repository->get($userId, $id, true);
            if (!in_array($next, self::TRANSITIONS[$application['status']] ?? [], true)) {
                throw new HttpException(
                    'This application cannot move from ' .
                        label($application['status']) .
                        ' to ' .
                        label($next) .
                        '.',
                );
            }
            $changes = ['status' => $next];
            if ($next === 'approved') {
                $this->reviewable($application);
                $changes['approved_at'] = date('Y-m-d H:i:s');
            }
            if ($next === 'submitted') {
                if (trim($confirmation) === '') {
                    throw new HttpException(
                        'Record the external submission confirmation before marking this application submitted.',
                    );
                }
                $changes['submitted_at'] = date('Y-m-d H:i:s');
                $changes['confirmation'] = $confirmation;
            }
            if ($next === 'ready_for_review') {
                $changes['approved_at'] = null;
            }
            $repository->update($userId, $id, $changes);
            (new ActivityRepository())->log(
                $userId,
                'application.status',
                'Moved application #' . $id . ' to ' . label($next) . '.',
            );
        });
    }

    private function reviewable(array $application): void
    {
        if (
            !$application['cv_document_id'] ||
            !$application['letter_document_id'] ||
            !$application['documents_verified_at']
        ) {
            throw new HttpException(
                'Attach and verify the CV and cover letter before approving this application.',
            );
        }
        if (!$application['job_verified_at']) {
            throw new HttpException(
                'Verify the job details and application instructions before approval.',
            );
        }
        foreach (['cv_document_id', 'letter_document_id'] as $field) {
            \App\Services\Documents\DocumentStorage::assertAvailable(
                (new DocumentRepository())->get(
                    (int) $application['user_id'],
                    (int) $application[$field],
                ),
            );
        }
        if ($application['deadline'] && $application['deadline'] < date('Y-m-d')) {
            throw new HttpException('This vacancy has expired.');
        }
    }

    public function send(int $userId, int $id): void
    {
        (new Entitlements())->requirePaid($userId);
        $repository = new ApplicationRepository();
        $settings = (new SettingsRepository())->get($userId, 'automation');
        $application = Database::transaction(function () use (
            $repository,
            $settings,
            $userId,
            $id,
        ): array {
            // Serialise per-user sends so concurrent workers cannot exceed daily limits.
            $repository->lockUser($userId);
            $application = $repository->get($userId, $id, true);
            if ($settings['paused'] || $settings['mode'] === 'manual') {
                throw new HttpException(
                    'Submission is disabled in Manual mode or while automation is paused.',
                );
            }
            if (
                $application['status'] !== 'approved' ||
                $application['submitted_at'] ||
                $application['send_key']
            ) {
                throw new HttpException(
                    'This application is not eligible for submission, or a send has already been attempted.',
                );
            }
            $this->reviewable($application);
            if (
                $application['method'] !== 'email' ||
                !in_array('email', $settings['permitted_methods'], true)
            ) {
                throw new HttpException('Use the employer website to complete this application.');
            }
            if (
                !filter_var($application['application_email'], FILTER_VALIDATE_EMAIL) ||
                trim($application['subject']) === '' ||
                trim($application['email_body'] ?? '') === ''
            ) {
                throw new HttpException(
                    'A verified recipient, subject and email body are required.',
                );
            }
            if (
                $settings['mode'] === 'automatic' &&
                ($application['eligibility'] !== 'eligible' ||
                    (int) $application['score'] < (int) $settings['minimum_match'])
            ) {
                throw new HttpException(
                    'This application does not satisfy the automatic eligibility and match requirements.',
                );
            }
            if ($repository->sentToday($userId) >= (int) $settings['daily_limit']) {
                throw new HttpException('The daily application limit has been reached.');
            }
            if (!(new IntegrationRepository())->connection($userId)) {
                throw new HttpException('Connect Gmail before submitting email applications.');
            }
            $key = bin2hex(random_bytes(32));
            $repository->update($userId, $id, [
                'status' => 'submitting',
                'send_key' => $key,
                'last_error' => null,
            ]);
            $application['send_key'] = $key;
            return $application;
        });
        try {
            $documents = new DocumentRepository();
            $response = (new \App\Services\Applications\GmailAdapter())->submit(
                $userId,
                $application,
                [
                    $documents->get($userId, (int) $application['cv_document_id']),
                    $documents->get($userId, (int) $application['letter_document_id']),
                ],
            );
            $repository->update($userId, $id, [
                'status' => 'submitted',
                'submitted_at' => date('Y-m-d H:i:s'),
                'confirmation' => 'Gmail message ID: ' . $response['id'],
                'provider_message_id' => $response['id'],
                'provider_thread_id' => $response['threadId'] ?? null,
            ]);
            (new ActivityRepository())->notify(
                $userId,
                'submission',
                'Application submitted',
                $application['title'],
                '/applications/' . $id,
            );
        } catch (\Throwable $exception) {
            $repository->update($userId, $id, [
                'status' => 'awaiting_external_action',
                'last_error' =>
                    'Delivery was not confirmed. Check Gmail Sent using Message-ID ' .
                    $application['send_key'] .
                    '@jobflow.local before taking further action.',
            ]);
            throw $exception;
        }
    }
}
