<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Core\Database;
use App\Core\HttpException;
use App\Repositories\AccountRepository;
use App\Repositories\DocumentRepository;
use App\Services\AI\AiService;
use App\Services\Entitlements;
use App\Services\OnboardingService;

final class CvReviewService
{
    public function grade(int $userId, int $documentId): array
    {
        $document = (new DocumentRepository())->get($userId, $documentId);
        DocumentStorage::assertAvailable($document);
        if (($document['processing_status'] ?? 'ready') !== 'ready') {
            throw new HttpException(
                'Your CV is still being read or could not be checked. Wait for processing to finish or upload a valid copy.',
                409,
            );
        }
        if (!in_array($document['kind'], ['master_cv', 'tailored_cv'], true)) {
            throw new HttpException('Choose a CV document for this check.');
        }
        return Database::transaction(function () use ($userId, $documentId, $document): array {
            $accounts = new AccountRepository();
            $accounts->lockUser($userId);
            $preferences = $accounts->preferences($userId);
            $field = $preferences['field_key'];
            $existing = $accounts->review($userId, $documentId, $field);
            if ($existing) {
                return json_decode($existing['result_json'], true, 64, JSON_THROW_ON_ERROR);
            }
            if ($preferences['initial_grade_used']) {
                (new Entitlements())->requirePaid($userId);
            }
            $result = $this->assess(
                $document['extracted_text'] ?? '',
                $field,
                $preferences['role_keywords'],
            );
            $accounts->saveReview($userId, $documentId, $field, $result);
            if ($result['score'] !== null) {
                $accounts->savePreferences($userId, ['initial_grade_used' => 1]);
            }
            return $result;
        });
    }

    public function assess(string $text, string $field, string $keywords = ''): array
    {
        $definition =
            OnboardingService::FIELDS[$field] ??
            throw new HttpException('Choose a valid job field.');
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 30) {
            return [
                'score' => null,
                'label' => 'A readable CV is needed',
                'field' => $definition[0],
                'method' => 'Text and structure check',
                'checks' => [],
                'message' =>
                    'We could not read enough text to grade this CV. Upload a text-based PDF or DOCX. Your original file is saved.',
            ];
        }
        $terms = array_filter(
            array_merge($definition[1], array_map('trim', explode(',', $keywords))),
        );
        $matched = [];
        foreach ($terms as $term) {
            if (\App\Services\Discovery\KeywordMatcher::contains($text, $term)) {
                $matched[] = $term;
            }
        }
        $checks = [
            [
                'name' => 'Contact details',
                'passed' =>
                    (bool) preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/iu', $text) &&
                    (bool) preg_match('/\+?\d[\d\s().-]{7,}\d/u', $text),
                'points' => 15,
            ],
            [
                'name' => 'Professional summary',
                'passed' => (bool) preg_match(
                    '/\b(summary|profile|objective|about me)\b/iu',
                    $text,
                ),
                'points' => 10,
            ],
            [
                'name' => 'Experience or projects',
                'passed' => (bool) preg_match(
                    '/\b(experience|employment|projects|internship|volunteering)\b/iu',
                    $text,
                ),
                'points' => 20,
            ],
            [
                'name' => 'Education or qualifications',
                'passed' => (bool) preg_match(
                    '/\b(education|qualifications|degree|diploma|bachelor|certificate)\b/iu',
                    $text,
                ),
                'points' => 15,
            ],
            [
                'name' => 'Skills section',
                'passed' => (bool) preg_match('/\b(skills|competencies|expertise)\b/iu', $text),
                'points' => 10,
            ],
            [
                'name' => 'Evidence of achievements',
                'passed' => (bool) preg_match(
                    '/\b(increased|reduced|delivered|improved|built|managed|led|achieved|trained)\b.{0,100}\d|\d+\s*%/iu',
                    $text,
                ),
                'points' => 10,
            ],
            [
                'name' => 'Relevant field terms',
                'passed' => count($matched) >= min(3, max(1, count($terms))),
                'points' => 20,
            ],
        ];
        $score = array_sum(
            array_map(fn($check) => $check['passed'] ? $check['points'] : 0, $checks),
        );
        return [
            'score' => $score,
            'label' =>
                $score >= 80
                    ? 'A strong starting point'
                    : ($score >= 50
                        ? 'Room to stand out'
                        : 'Let’s strengthen your CV'),
            'field' => $definition[0],
            'method' => 'Text and structure check',
            'checks' => $checks,
            'matched_terms' => $matched,
            'message' =>
                'This checks readable sections and field terms. It is not an ATS score, an AI assessment, or a prediction of hiring success.',
        ];
    }

    public function advice(int $userId): array
    {
        (new Entitlements())->requirePaid($userId);
        $accounts = new AccountRepository();
        $review =
            $accounts->latestReview($userId) ??
            throw new HttpException('Upload and check a CV first.');
        if ($review['score'] === null) {
            throw new HttpException('Upload a readable CV before requesting improvements.');
        }
        $document = (new DocumentRepository())->get($userId, (int) $review['document_id']);
        DocumentStorage::assertAvailable($document);
        $result = (new AiService())->run($userId, 'cv_advice', [
            'cv_text' => $document['extracted_text'],
            'field' => OnboardingService::FIELDS[$review['field_key']][0],
            'target_roles' => $accounts->preferences($userId)['role_keywords'],
        ]);
        $accounts->saveAdvice($userId, (int) $review['id'], $result);
        return $result;
    }
}
