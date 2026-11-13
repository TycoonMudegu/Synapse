<?php

declare(strict_types=1);

namespace App\Repositories;

final class SettingsRepository extends Repository
{
    public const DEFAULTS = [
        'discovery' => [
            'keywords' => '',
            'excluded_keywords' => '',
            'locations' => 'Kenya',
            'categories' => '',
            'sources_per_run' => 5,
            'jobs_per_run' => 25,
            'daily_limit' => 100,
            'interval_minutes' => 360,
            'ignore_expired' => true,
            'hide_duplicates' => true,
            'minimum_salary' => 0,
            'remote' => 'any',
            'minimum_match' => 70,
            'run_budget_usd' => 1,
        ],
        'automation' => [
            'mode' => 'manual',
            'paused' => false,
            'discovery_enabled' => false,
            'analysis_enabled' => false,
            'preparation_enabled' => false,
            'minimum_match' => 80,
            'daily_limit' => 5,
            'permitted_methods' => ['email'],
        ],
        'ai' => [
            'screening_provider' => 0,
            'tailoring_provider' => 0,
            'letter_provider' => 0,
            'fallback_provider' => 0,
            'monthly_limit_usd' => 5,
            'weights' => [
                'skills' => 35,
                'experience' => 25,
                'qualifications' => 20,
                'preferences' => 20,
            ],
        ],
        'gmail' => [
            'signature' => '',
            'monitor_enabled' => false,
            'response_tracking' => false,
            'sync_minutes' => 30,
            'alert_query' => '',
        ],
        'documents' => ['default_format' => 'pdf'],
    ];

    public function get(int $userId, string $section): array
    {
        $row = $this->one(
            'SELECT value_json FROM automation_settings WHERE user_id = ? AND section = ?',
            [$userId, $section],
        );
        return array_replace(
            self::DEFAULTS[$section] ?? [],
            $row ? json_decode($row['value_json'], true, 64, JSON_THROW_ON_ERROR) : [],
        );
    }

    public function save(int $userId, string $section, array $settings): void
    {
        $this->query(
            'INSERT INTO automation_settings (user_id, section, value_json) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value_json = VALUES(value_json)',
            [$userId, $section, json_encode($settings, JSON_THROW_ON_ERROR)],
        );
    }

    public function providers(int $userId): array
    {
        return $this->rows(
            'SELECT id, name, endpoint, model, options_json, health, tested_at FROM ai_providers WHERE user_id = ? ORDER BY id',
            [$userId],
        );
    }

    public function provider(int $userId, int $id): ?array
    {
        return $this->one('SELECT * FROM ai_providers WHERE user_id = ? AND id = ?', [
            $userId,
            $id,
        ]);
    }

    public function saveProvider(int $userId, int $id, array $data): int
    {
        if ($id > 0) {
            $this->query(
                "UPDATE ai_providers SET name = ?, endpoint = ?, encrypted_key = ?, model = ?, options_json = ?, health = 'untested', tested_at = NULL WHERE id = ? AND user_id = ?",
                [...$data, $id, $userId],
            );
            return $id;
        }
        $this->query(
            'INSERT INTO ai_providers (name, endpoint, encrypted_key, model, options_json, user_id) VALUES (?, ?, ?, ?, ?, ?)',
            [...$data, $userId],
        );
        return (int) $this->db->lastInsertId();
    }

    public function providerHealth(int $userId, int $id, string $health): void
    {
        $this->query(
            'UPDATE ai_providers SET health = ?, tested_at = NOW() WHERE id = ? AND user_id = ?',
            [$health, $id, $userId],
        );
    }

    public function usage(int $userId): array
    {
        return $this->rows(
            "SELECT task, SUM(input_tokens) AS input_tokens, SUM(output_tokens) AS output_tokens, SUM(COALESCE(estimated_cost, reserved_cost)) AS estimated_cost, COUNT(*) AS requests FROM ai_usage_records WHERE user_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01') GROUP BY task",
            [$userId],
        );
    }

    public function reserveUsage(
        int $userId,
        array $provider,
        string $task,
        float $cost,
        float $limit,
    ): int {
        return \App\Core\Database::transaction(function () use (
            $userId,
            $provider,
            $task,
            $cost,
            $limit,
        ): int {
            $this->query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId]);
            $spent = (float) $this->query(
                "SELECT COALESCE(SUM(COALESCE(estimated_cost, reserved_cost)), 0) FROM ai_usage_records WHERE user_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')",
                [$userId],
            )->fetchColumn();
            if ($cost <= 0 || $limit <= 0 || $spent + $cost > $limit) {
                throw new \App\Core\HttpException(
                    'The AI spending limit would be exceeded. Check model pricing and monthly budget.',
                );
            }
            $this->query(
                'INSERT INTO ai_usage_records (user_id, provider_id, task, model, reserved_cost) VALUES (?, ?, ?, ?, ?)',
                [$userId, $provider['id'], $task, $provider['model'], $cost],
            );
            return (int) $this->db->lastInsertId();
        });
    }

    public function finishUsage(
        int $id,
        int $input,
        int $output,
        ?float $cost,
        string $status,
    ): void {
        $this->query(
            'UPDATE ai_usage_records SET input_tokens = ?, output_tokens = ?, estimated_cost = ?, status = ? WHERE id = ?',
            [$input, $output, $cost, $status, $id],
        );
    }
}
