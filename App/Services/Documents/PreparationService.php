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
    public function __construct(private readonly LayoutPreparer $layout = new LayoutPreparer()) {}

    public function prepare(int $userId, int $applicationId): void
    {
        (new \App\Services\Entitlements())->requirePaid($userId);
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
        $preferences = (new \App\Repositories\SettingsRepository())->get($userId, 'documents');
        $prepared = null;
        if ($preferences['layout_mode'] === 'preserve') {
            $prepared = $this->layout->build(
                $userId,
                $profile,
                $job,
                $preferences['default_format'],
            );
            $tailored = $prepared['profile'];
            $tailoring = ['changes' => $prepared['changes']];
            $letter = ['content' => $prepared['letter_text']];
        } else {
            $ai = new AiService();
            $tailoring = $ai->run($userId, 'tailoring', ['profile' => $profile, 'job' => $job]);
            $tailored = $profile;
            $tailored['summary'] = $tailoring['summary'];
            $tailored['employment'] = $this->select(
                $profile['employment'],
                $tailoring['employment_order'],
            );
            $tailored['skills'] = $this->select($profile['skills'], $tailoring['skill_order']);
            $templateId = (new \App\Repositories\AccountRepository())->preferences($userId)[
                'letter_document_id'
            ];
            $templateText = '';
            if ($templateId) {
                $templateText =
                    (new DocumentRepository())->get($userId, (int) $templateId)['extracted_text'] ??
                    '';
            }
            $letter = $ai->run($userId, 'letter', [
                'profile' => $profile,
                'job' => $job,
                'application_date' => date('j F Y'),
                'style_template' => mb_substr($templateText, 0, 10000),
            ]);
        }
        $this->validateLetter($letter['content']);
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
            $prepared,
            $preferences,
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
            $currentPreferences = (new \App\Repositories\SettingsRepository())->get(
                $userId,
                'documents',
            );
            if ($currentPreferences !== $preferences) {
                throw new HttpException(
                    'Your document preferences changed during preparation. Please try again.',
                );
            }
            $format = $preferences['default_format'];
            $exporter = new Exporter();
            $cvId = $prepared
                ? $exporter->rendered(
                    $userId,
                    $prepared['cv_bytes'],
                    $format,
                    'CV - ' . $job['employer'],
                    'tailored_cv',
                    $prepared['cv_text'],
                    $application['cv_document_id'] ? (int) $application['cv_document_id'] : null,
                )
                : $exporter->cv(
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
                'layout_mode' => $preferences['layout_mode'],
                'source_cv_id' => $prepared['source_cv_id'] ?? null,
                'source_letter_id' => $prepared['source_letter_id'] ?? null,
                'cv_edits' => $prepared['cv_edits'] ?? [],
                'document_text' => $prepared['cv_text'] ?? null,
            ]);
            $letterId =
                $prepared && $prepared['letter_bytes'] !== null
                    ? $exporter->rendered(
                        $userId,
                        $prepared['letter_bytes'],
                        $format,
                        'Cover letter - ' . $job['employer'],
                        'cover_letter',
                        $letter['content'],
                        $application['letter_document_id']
                            ? (int) $application['letter_document_id']
                            : null,
                    )
                    : $exporter->letter(
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

    public function validateLetter(string $content): void
    {
        if (
            trim($content) === '' ||
            preg_match('/\bx{3,}(?:[\s()\-]+x{3,})+\b/i', $content) ||
            preg_match(
                '/\[(?:today(?:[\x{2019}\x{0027}])?s?\s+date|date|hiring\s+manager[^\]\r\n]*|(?:your|company|recipient|address|name)[^\]\r\n]*)\]/iu',
                $content,
            )
        ) {
            throw new HttpException(
                'The cover letter still contains template placeholders or is empty. Prepare it again before using it.',
            );
        }
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
