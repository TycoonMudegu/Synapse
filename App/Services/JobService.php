<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Repositories\ActivityRepository;
use App\Repositories\JobRepository;
use App\Repositories\QueueRepository;
use App\Repositories\SettingsRepository;
use App\Services\Security\UrlGuard;
use App\Services\Security\Validator;

final class JobService
{
    public function normalise(array $data): array
    {
        $job = [];
        foreach (
            [
                'title' => 250,
                'employer' => 250,
                'description' => 60000,
                'requirements' => 20000,
                'responsibilities' => 20000,
                'qualifications' => 10000,
                'location' => 200,
                'instructions' => 20000,
                'email_subject' => 250,
            ]
            as $field => $length
        ) {
            $job[$field] = Validator::text(
                $data[$field] ?? '',
                str_replace('_', ' ', $field),
                $length,
                in_array($field, ['title', 'employer', 'description'], true),
            );
        }
        $job['employment_type'] = Validator::choice(
            $data['employment_type'] ?? 'not_specified',
            'employment type',
            [
                'not_specified',
                'full_time',
                'part_time',
                'contract',
                'internship',
                'temporary',
                'volunteer',
            ],
        );
        $job['workplace'] = Validator::choice(
            $data['workplace'] ?? 'not_specified',
            'work arrangement',
            ['not_specified', 'remote', 'hybrid', 'office'],
        );
        $job['application_method'] = Validator::choice(
            $data['application_method'] ?? 'manual',
            'application method',
            ['manual', 'email', 'external'],
        );
        $job['application_email'] = Validator::email($data['application_email'] ?? '') ?: null;
        if ($job['application_method'] === 'email' && !$job['application_email']) {
            throw new HttpException('Email applications require a verified recipient address.');
        }
        $job['deadline'] = Validator::date($data['deadline'] ?? null, 'application deadline');
        foreach (['salary_min', 'salary_max'] as $field) {
            $job[$field] =
                isset($data[$field]) && $data[$field] !== ''
                    ? Validator::number($data[$field], 'salary', 0, 999999999999)
                    : null;
        }
        if (
            $job['salary_min'] !== null &&
            $job['salary_max'] !== null &&
            $job['salary_min'] > $job['salary_max']
        ) {
            throw new HttpException('The maximum salary must be at least the minimum salary.');
        }
        $job['currency'] = Validator::text($data['currency'] ?? '', 'currency', 10) ?: null;
        if (($job['salary_min'] !== null || $job['salary_max'] !== null) && !$job['currency']) {
            throw new HttpException('Supply the advertised salary currency.');
        }
        $url = Validator::text($data['original_url'] ?? '', 'job URL', 2048);
        if ($url !== '') {
            $parts = parse_url($url);
            if (
                !filter_var($url, FILTER_VALIDATE_URL) ||
                ($parts['scheme'] ?? '') !== 'https' ||
                isset($parts['user']) ||
                isset($parts['pass'])
            ) {
                throw new HttpException('Enter a valid HTTPS job URL.');
            }
            $url = UrlGuard::canonical($url);
        }
        $job['original_url'] = $url ?: null;
        $job['canonical_hash'] = $url ? hash('sha256', $url) : null;
        $fingerprint = mb_strtolower(
            $job['title'] .
                '|' .
                $job['employer'] .
                '|' .
                $job['location'] .
                '|' .
                ($job['deadline'] ?? ''),
        );
        $job['fingerprint'] = hash('sha256', preg_replace('/\s+/', ' ', trim($fingerprint)));
        $job['verified_at'] = !empty($data['verified']) ? date('Y-m-d H:i:s') : null;
        $job['source_id'] = $data['source_id'] ?? null;
        $job['poster_document_id'] =
            (int) ($data['poster_document_id'] ?? 0) > 0 ? (int) $data['poster_document_id'] : null;
        return $job;
    }

    public function create(int $userId, array $data): array
    {
        $result = (new JobRepository())->insert($userId, $this->normalise($data));
        if (!$result['duplicate']) {
            (new ActivityRepository())->log(
                $userId,
                'job.saved',
                'Saved an opportunity: ' . $data['title'],
            );
            $settings = (new SettingsRepository())->get($userId, 'automation');
            if (
                $settings['analysis_enabled'] &&
                !$settings['paused'] &&
                (new AutomationService())->status($userId)['can_analyse']
            ) {
                (new QueueRepository())->enqueue($userId, 'analyse_job', [
                    'job_id' => $result['id'],
                ]);
            }
        }
        return $result;
    }
}
