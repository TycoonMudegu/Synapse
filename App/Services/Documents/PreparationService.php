<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Core\HttpException;
use App\Repositories\ActivityRepository;
use App\Repositories\ApplicationRepository;
use App\Repositories\DocumentRepository;
use App\Repositories\JobRepository;
use App\Repositories\ProfileRepository;
use App\Services\AI\AiService;

final class PreparationService
{
    public function prepare(int $userId, int $applicationId): void
    {
        $applications = new ApplicationRepository();
        $application = $applications->get($userId, $applicationId);
        if (!in_array($application['status'], ['draft', 'ready_for_review'], true)) {
            throw new HttpException('Only draft or review applications can be regenerated.');
        }
        $profile = (new ProfileRepository())->get($userId);
        if (empty($profile['verified_at']) || $profile['summary'] === '') {
            throw new HttpException('Complete and verify your career profile first.');
        }
        $job = (new JobRepository())->get($userId, (int) $application['job_id']);
        $ai = new AiService();
        $tailoring = $ai->run($userId, 'tailoring', ['profile' => $profile, 'job' => $job]);
        $tailored = $profile;
        $tailored['summary'] = $tailoring['summary'];
        $tailored['employment'] = $this->select(
            $profile['employment'],
            $tailoring['employment_order'],
        );
        $tailored['skills'] = $this->select($profile['skills'], $tailoring['skill_order']);
        $letter = $ai->run($userId, 'letter', ['profile' => $profile, 'job' => $job]);
        \App\Core\Database::transaction(function () use (
            $userId,
            $applicationId,
            $applications,
            $application,
            $profile,
            $job,
            $tailored,
            $tailoring,
            $letter,
        ): void {
            $applications->lockUser($userId);
            $latest = $applications->get($userId, $applicationId, true);
            $latestJob = (new JobRepository())->get($userId, (int) $application['job_id']);
            if (
                !in_array($latest['status'], ['draft', 'ready_for_review'], true) ||
                $latest['revision'] !== $application['revision'] ||
                $latestJob['revision'] !== $job['revision']
            ) {
                throw new HttpException(
                    'The job or application changed during preparation. Review it and prepare again.',
                );
            }
            $current = (new ProfileRepository())->get($userId);
            if ($current['revision'] !== $profile['revision']) {
                throw new HttpException(
                    'Your profile changed during preparation. Please try again.',
                );
            }
            $format = (new \App\Repositories\SettingsRepository())->get($userId, 'documents')[
                'default_format'
            ];
            $exporter = new Exporter();
            $cvId = $exporter->cv(
                $userId,
                $tailored,
                $format,
                'CV - ' . $job['employer'],
                $application['cv_document_id'] ? (int) $application['cv_document_id'] : null,
            );
            (new DocumentRepository())->addCv($userId, $cvId, [
                'profile' => $tailored,
                'original_summary' => $profile['summary'],
                'changes' => $tailoring['changes'],
            ]);
            $letterId = $exporter->letter(
                $userId,
                $letter['content'],
                $format,
                'Cover letter - ' . $job['employer'],
                $application['letter_document_id']
                    ? (int) $application['letter_document_id']
                    : null,
            );
            $applications->saveLetter($userId, $applicationId, $letter['content'], $cvId);
            $applications->update($userId, $applicationId, [
                'cv_document_id' => $cvId,
                'letter_document_id' => $letterId,
                'status' => 'ready_for_review',
                'documents_verified_at' => null,
                'approved_at' => null,
                'email_body' => $letter['content'],
            ]);
        });
        $activity = new ActivityRepository();
        $activity->log(
            $userId,
            'application.prepared',
            'Prepared a tailored CV and cover letter for ' .
                $job['title'] .
                '. Review is required.',
        );
        $activity->notify(
            $userId,
            'review',
            'Your application is ready to review',
            $job['title'],
            '/applications/' . $applicationId,
        );
    }

    public function select(array $original, array $indices): array
    {
        if (count($indices) !== count(array_unique($indices))) {
            throw new HttpException(
                'The AI returned duplicate career entries. Nothing has been applied.',
            );
        }
        $selected = [];
        foreach ($indices as $index) {
            if (!is_int($index) || !array_key_exists($index, $original)) {
                throw new HttpException(
                    'The AI tried to select an unknown career entry. Nothing has been applied.',
                );
            }
            $selected[] = $original[$index];
        }
        // Include remaining verified entries so tailoring cannot silently remove qualifications or history.
        foreach ($original as $index => $entry) {
            if (!in_array($index, $indices, true)) {
                $selected[] = $entry;
            }
        }
        return $selected;
    }
}
