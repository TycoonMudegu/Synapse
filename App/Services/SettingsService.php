<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Repositories\ActivityRepository;
use App\Repositories\SettingsRepository;
use App\Services\Security\Crypto;
use App\Services\Security\UrlGuard;
use App\Services\Security\Validator;

final class SettingsService
{
    public function save(int $userId, string $section, array $input): void
    {
        $repository = new SettingsRepository();
        if (!isset(SettingsRepository::DEFAULTS[$section])) {
            throw new HttpException('Unknown settings section.', 404);
        }
        $settings = $repository->get($userId, $section);
        $rules = match ($section) {
            'discovery' => [
                'keywords' => ['text', 2000],
                'excluded_keywords' => ['text', 2000],
                'locations' => ['text', 2000],
                'categories' => ['text', 2000],
                'sources_per_run' => ['int', 1, 20],
                'jobs_per_run' => ['int', 1, 200],
                'daily_limit' => ['int', 1, 1000],
                'interval_minutes' => ['int', 15, 10080],
                'minimum_salary' => ['number', 0, 999999999],
                'minimum_match' => ['int', 0, 100],
                'run_budget_usd' => ['number', 0.01, 100],
                'ignore_expired' => ['bool'],
                'hide_duplicates' => ['bool'],
                'remote' => ['choice', ['any', 'remote', 'hybrid', 'office']],
            ],
            'automation' => [
                'mode' => ['choice', ['manual', 'approval', 'automatic']],
                'paused' => ['bool'],
                'discovery_enabled' => ['bool'],
                'analysis_enabled' => ['bool'],
                'preparation_enabled' => ['bool'],
                'minimum_match' => ['int', 0, 100],
                'daily_limit' => ['int', 1, 100],
            ],
            'ai' => [
                'screening_provider' => ['int', 0, 999999999],
                'tailoring_provider' => ['int', 0, 999999999],
                'letter_provider' => ['int', 0, 999999999],
                'fallback_provider' => ['int', 0, 999999999],
                'monthly_limit_usd' => ['number', 0.01, 10000],
            ],
            'gmail' => [
                'signature' => ['text', 4000],
                'monitor_enabled' => ['bool'],
                'response_tracking' => ['bool'],
                'sync_minutes' => ['int', 5, 1440],
                'alert_query' => ['text', 1000],
            ],
            'documents' => ['default_format' => ['choice', ['pdf', 'docx']]],
        };
        foreach ($rules as $field => $rule) {
            $value = $input[$field] ?? ($rule[0] === 'bool' ? false : $settings[$field]);
            $settings[$field] = match ($rule[0]) {
                'text' => Validator::text($value, $field, $rule[1]),
                'int' => (int) Validator::number($value, $field, $rule[1], $rule[2]),
                'number' => Validator::number($value, $field, $rule[1], $rule[2]),
                'bool' => filter_var($value, FILTER_VALIDATE_BOOL),
                'choice' => Validator::choice($value, $field, $rule[1]),
            };
        }
        if ($section === 'automation') {
            if ($settings['mode'] === 'automatic' && empty($input['confirm_automatic'])) {
                throw new HttpException('Explicitly confirm Automatic mode before enabling it.');
            }
            $settings['permitted_methods'] = !empty($input['allow_email']) ? ['email'] : [];
        }
        if ($section === 'ai') {
            foreach (
                ['screening_provider', 'tailoring_provider', 'letter_provider', 'fallback_provider']
                as $field
            ) {
                if ($settings[$field] > 0 && !$repository->provider($userId, $settings[$field])) {
                    throw new HttpException('Select a provider belonging to this account.');
                }
            }
            $weights = [];
            foreach (['skills', 'experience', 'qualifications', 'preferences'] as $field) {
                $weights[$field] = (int) Validator::number(
                    $input['weight_' . $field] ?? $settings['weights'][$field],
                    $field . ' weight',
                    0,
                    100,
                );
            }
            if (array_sum($weights) !== 100) {
                throw new HttpException('Matching weights must add up to 100.');
            }
            $settings['weights'] = $weights;
        }
        if (
            $section === 'gmail' &&
            $settings['monitor_enabled'] &&
            $settings['alert_query'] === '' &&
            (new \App\Repositories\SourceRepository())->emailSources($userId) === []
        ) {
            throw new HttpException(
                'Supply a specific Gmail search query for the job alerts you want to monitor.',
            );
        }
        $repository->save($userId, $section, $settings);
        (new ActivityRepository())->log(
            $userId,
            'settings.saved',
            'Updated ' . $section . ' settings.',
        );
    }

    public function provider(int $userId, array $input): int
    {
        $repository = new SettingsRepository();
        $id = (int) ($input['id'] ?? 0);
        $existing = $id > 0 ? $repository->provider($userId, $id) : null;
        if ($id > 0 && !$existing) {
            throw new HttpException('AI provider not found.', 404);
        }
        $name = Validator::text($input['name'] ?? '', 'provider name', 100, true);
        $endpoint = rtrim(
            Validator::text($input['endpoint'] ?? '', 'API base URL', 2000, true),
            '/',
        );
        UrlGuard::validate($endpoint);
        if (
            parse_url($endpoint, PHP_URL_QUERY) !== null ||
            parse_url($endpoint, PHP_URL_FRAGMENT) !== null
        ) {
            throw new HttpException(
                'The API base URL must not contain a query string or fragment.',
            );
        }
        $model = Validator::text($input['model'] ?? '', 'model identifier', 200, true);
        $key = Validator::text($input['api_key'] ?? '', 'API key', 10000);
        if ($key === '' && !$existing) {
            throw new HttpException('An API key is required for a new provider.');
        }
        $options = [
            'temperature' =>
                ($input['temperature'] ?? '') === ''
                    ? ''
                    : Validator::number($input['temperature'], 'temperature', 0, 2),
            'max_tokens' => (int) Validator::number(
                $input['max_tokens'] ?? 3000,
                'maximum output tokens',
                64,
                16000,
            ),
            'timeout' => (int) Validator::number(
                $input['timeout'] ?? 60,
                'request timeout',
                10,
                120,
            ),
            'input_per_million' => Validator::number(
                $input['input_per_million'] ?? 0,
                'input price per million tokens',
                0.001,
                1000,
            ),
            'output_per_million' => Validator::number(
                $input['output_per_million'] ?? 0,
                'output price per million tokens',
                0.001,
                1000,
            ),
            'vision_token_reserve' => (int) Validator::number(
                $input['vision_token_reserve'] ?? 16000,
                'vision token reserve',
                1000,
                100000,
            ),
            'response_format' => Validator::choice(
                $input['response_format'] ?? 'json_object',
                'JSON response mode',
                ['json_object', 'json_schema'],
            ),
            'token_parameter' => Validator::choice(
                $input['token_parameter'] ?? 'max_tokens',
                'output token parameter',
                ['max_tokens', 'max_completion_tokens'],
            ),
        ];
        return $repository->saveProvider($userId, $id, [
            $name,
            $endpoint,
            $key !== '' ? Crypto::encrypt($key) : $existing['encrypted_key'],
            $model,
            json_encode($options, JSON_THROW_ON_ERROR),
        ]);
    }
}
