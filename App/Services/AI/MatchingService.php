<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Core\HttpException;
use App\Repositories\ActivityRepository;
use App\Repositories\JobRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\SettingsRepository;

final class MatchingService
{
    public function analyse(int $userId, int $jobId): array
    {
        $profile = (new ProfileRepository())->get($userId);
        if (empty($profile['verified_at'])) {
            throw new HttpException(
                'Verify and save your career profile before analysing opportunities.',
            );
        }
        $jobs = new JobRepository();
        $job = $jobs->get($userId, $jobId);
        $result = (new AiService())->run($userId, 'screening', [
            'profile' => $profile,
            'job' => $job,
            'scoring_weights' => (new SettingsRepository())->get($userId, 'ai')['weights'],
        ]);
        foreach ($result['mandatory_checks'] as $check) {
            if ($check['status'] === 'missing') {
                $result['eligibility'] = 'ineligible';
                break;
            }
            if ($check['status'] === 'uncertain' && $result['eligibility'] === 'eligible') {
                $result['eligibility'] = 'uncertain';
            }
        }
        // Keep mandatory eligibility distinct from the numeric match score.
        if ($result['mandatory_checks'] === []) {
            $result['eligibility'] = 'uncertain';
        }
        \App\Core\Database::transaction(function () use (
            $jobs,
            $userId,
            $jobId,
            $profile,
            $job,
            $result,
        ): void {
            $jobs->lockUser($userId);
            $currentJob = $jobs->get($userId, $jobId);
            if ($currentJob['revision'] !== $job['revision']) {
                throw new HttpException(
                    'The job changed during analysis. Please analyse it again.',
                );
            }
            $current = (new ProfileRepository())->get($userId);
            if ($current['revision'] !== $profile['revision']) {
                throw new HttpException(
                    'Your profile changed during analysis. Please analyse the job again.',
                );
            }
            $jobs->saveMatch($userId, $jobId, $result, $profile['updated_at']);
        });
        $activity = new ActivityRepository();
        $activity->log(
            $userId,
            'job.analysed',
            'Completed a suitability analysis for ' . $job['title'] . '.',
        );
        $activity->notify(
            $userId,
            'match',
            'Job analysis complete',
            $job['title'] . ' · ' . $result['score'] . '% match',
            '/jobs/' . $jobId,
        );
        return $result;
    }
}
