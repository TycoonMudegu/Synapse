<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Repositories\AccountRepository;
use App\Repositories\QueueRepository;
use App\Repositories\SettingsRepository;
use App\Repositories\SourceRepository;
use App\Services\Discovery\SourceCatalog;
use App\Services\Security\Crypto;
use App\Services\Security\Validator;

final class OnboardingService
{
    public const FIELDS = [
        'technology' => [
            'Technology & IT',
            [
                'software',
                'developer',
                'programming',
                'network',
                'database',
                'cloud',
                'IT support',
                'security',
                'systems',
            ],
        ],
        'finance' => [
            'Finance & accounting',
            ['finance', 'accounting', 'audit', 'budget', 'tax', 'bookkeeping', 'financial'],
        ],
        'marketing' => [
            'Marketing & communications',
            ['marketing', 'communications', 'content', 'brand', 'campaign', 'social media'],
        ],
        'sales' => [
            'Sales & customer service',
            ['sales', 'customer', 'service', 'client', 'revenue', 'business development'],
        ],
        'administration' => [
            'Administration & HR',
            ['administration', 'administrative', 'human resources', 'recruitment', 'office', 'HR'],
        ],
        'healthcare' => [
            'Healthcare',
            ['health', 'clinical', 'nursing', 'patient', 'medical', 'hospital'],
        ],
        'engineering' => [
            'Engineering & construction',
            ['engineering', 'construction', 'civil', 'mechanical', 'electrical', 'project'],
        ],
        'education' => [
            'Education & training',
            ['education', 'teaching', 'training', 'curriculum', 'learning', 'teacher'],
        ],
        'operations' => [
            'Operations & logistics',
            ['operations', 'logistics', 'procurement', 'supply chain', 'warehouse', 'transport'],
        ],
        'other' => ['Other fields', []],
    ];

    public function sources(string $field): array
    {
        $configured = (new AccountRepository())->site('job_sources');
        $available = [];
        // JSON object key order can change in storage; use the catalogue's display order.
        foreach (SourceCatalog::SOURCES as $key => $definition) {
            $source = $configured[$key] ?? [];
            if (empty($source['enabled']) || ($source['health'] ?? '') !== 'connected') {
                continue;
            }
            // Existing category collectors currently cover technology vacancies only.
            if ($field !== 'technology' && $key !== 'myjobmag') {
                continue;
            }
            $available[$key] = $definition;
        }
        return $available;
    }

    public function preferences(int $userId, array $input): void
    {
        $field = Validator::choice(
            $input['field_key'] ?? '',
            'job field',
            array_keys(self::FIELDS),
        );
        $keywords = Validator::text($input['role_keywords'] ?? '', 'preferred job titles', 500);
        if ($field === 'other' && $keywords === '') {
            throw new HttpException('Enter at least one preferred job title for your field.');
        }
        $location = Validator::text($input['location'] ?? 'Kenya', 'location', 200, true);
        $keys = $input['sources'] ?? [];
        if (\App\Services\Discovery\CatalogueDiscovery::enabled()) {
            $keys = [];
        }
        if (!is_array($keys) || !array_is_list($keys) || count($keys) > 7) {
            throw new HttpException('Choose valid job sites.');
        }
        $available = $this->sources($field);
        foreach ($keys as $key) {
            if (!is_string($key) || !isset($available[$key])) {
                throw new HttpException('Choose from the available sites for your field.');
            }
        }
        Database::transaction(function () use ($userId, $field, $keywords, $location, $keys): void {
            $accounts = new AccountRepository();
            $accounts->savePreferences($userId, [
                'field_key' => $field,
                'role_keywords' => $keywords,
                'location' => $location,
                'source_keys' => json_encode(
                    array_values(array_unique($keys)),
                    JSON_THROW_ON_ERROR,
                ),
            ]);
            $settings = new SettingsRepository();
            $settings->save(
                $userId,
                'discovery',
                array_replace($settings->get($userId, 'discovery'), [
                    'role_focus' => $field === 'technology' ? 'technology' : 'any',
                    'keywords' =>
                        $keywords !== '' ? $keywords : implode(',', self::FIELDS[$field][1]),
                    'locations' => $location,
                ]),
            );
            $db = Database::connection();
            $db->prepare(
                'UPDATE user_profiles SET location = ?, preferences_json = ?, verified_at = NULL, revision = revision + 1 WHERE user_id = ?',
            )->execute([
                $location,
                json_encode(
                    ['field' => $field, 'roles' => $keywords, 'location' => $location],
                    JSON_THROW_ON_ERROR,
                ),
                $userId,
            ]);
            $db->prepare('DELETE FROM match_results WHERE user_id = ?')->execute([$userId]);
            $db->prepare(
                "UPDATE applications SET documents_verified_at = NULL, approved_at = NULL, revision = revision + 1, status = CASE WHEN status = 'approved' THEN 'ready_for_review' ELSE status END WHERE user_id = ? AND status IN ('draft', 'ready_for_review', 'approved')",
            )->execute([$userId]);
            $sources = new SourceRepository();
            foreach ($sources->all($userId) as $source) {
                $sources->toggle($userId, (int) $source['id'], false);
            }
            foreach ($keys as $key) {
                $id = $sources->ensurePreset($userId, $key, SourceCatalog::get($key));
                $sources->health($userId, $id, true);
            }
            $accounts->step($userId, 2);
        });
    }

    public function provider(int $userId, array $input): int
    {
        $type = Validator::choice($input['provider'] ?? 'gemini', 'AI provider', [
            'gemini',
            'compatible',
        ]);
        $settings = new SettingsRepository();
        $endpoint =
            $type === 'gemini'
                ? 'https://generativelanguage.googleapis.com/v1beta/openai'
                : $input['endpoint'] ?? '';
        $model = Validator::text($input['model'] ?? '', 'model', 200, true);
        $id = (new SettingsService())->provider($userId, [
            'id' => $settings->get($userId, 'ai')['screening_provider'],
            'name' => $type === 'gemini' ? 'My Gemini' : 'My AI provider',
            'endpoint' => $endpoint,
            'model' => $model,
            'api_key' => $input['api_key'] ?? '',
            'input_per_million' => $input['input_per_million'] ?? '',
            'output_per_million' => $input['output_per_million'] ?? '',
        ]);
        $this->assign($userId, $id);
        return $id;
    }

    public function ensureManagedAi(int $userId): void
    {
        $settings = new SettingsRepository();
        $assignments = $settings->get($userId, 'ai');
        if ($settings->provider($userId, (int) $assignments['screening_provider'])) {
            return;
        }
        (new Entitlements())->requirePaid($userId);
        $config = (new AccountRepository())->site('ai_provider');
        if (Env::get('GEMINI_API_KEY') !== '') {
            $config = [
                'name' => 'Synapse AI',
                'endpoint' => 'https://generativelanguage.googleapis.com/v1beta/openai',
                'encrypted_key' => Crypto::encrypt(Env::get('GEMINI_API_KEY')),
                'model' => Env::get('GEMINI_MODEL'),
                'options_json' => json_encode(
                    [
                        'max_tokens' => 4000,
                        'timeout' => 90,
                        'temperature' => '',
                        'input_per_million' => (float) Env::get('GEMINI_INPUT_PER_MILLION', '0'),
                        'output_per_million' => (float) Env::get('GEMINI_OUTPUT_PER_MILLION', '0'),
                    ],
                    JSON_THROW_ON_ERROR,
                ),
            ];
        }
        if (empty($config['encrypted_key']) || empty($config['model'])) {
            throw new HttpException(
                'Synapse AI is being set up. You can add your own AI key in Settings in the meantime.',
                503,
            );
        }
        $options = json_decode($config['options_json'], true, 64, JSON_THROW_ON_ERROR);
        if (
            ($options['input_per_million'] ?? 0) <= 0 ||
            ($options['output_per_million'] ?? 0) <= 0
        ) {
            throw new HttpException(
                'Synapse AI model pricing needs to be configured by the site owner.',
                503,
            );
        }
        $options['managed'] = true;
        $id = $settings->saveProvider($userId, 0, [
            'Synapse AI',
            $config['endpoint'],
            $config['encrypted_key'],
            $config['model'],
            json_encode($options, JSON_THROW_ON_ERROR),
        ]);
        $this->assign($userId, $id);
    }

    private function assign(int $userId, int $id): void
    {
        $settings = new SettingsRepository();
        $ai = $settings->get($userId, 'ai');
        foreach (['screening_provider', 'tailoring_provider', 'letter_provider'] as $task) {
            $ai[$task] = $id;
        }
        $settings->save($userId, 'ai', $ai);
    }

    public function finish(int $userId): void
    {
        (new AccountRepository())->complete($userId);
        // The worker refreshes selected public feeds without needing a paid plan.
        if ((new SourceRepository())->enabled($userId, 1)) {
            (new QueueRepository())->enqueue($userId, 'discovery');
        }
    }
}
