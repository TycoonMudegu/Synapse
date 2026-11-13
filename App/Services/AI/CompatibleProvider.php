<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Core\HttpException;
use App\Http\HttpClient;
use App\Services\Security\Crypto;

final class CompatibleProvider implements Provider
{
    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    public function generate(array $configuration, array $messages, array $schema): array
    {
        $options = json_decode($configuration['options_json'], true, 64, JSON_THROW_ON_ERROR);
        $payload = [
            'model' => $configuration['model'],
            'messages' => $messages,
            'response_format' =>
                ($options['response_format'] ?? 'json_object') === 'json_schema'
                    ? [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'jobflow_result',
                            'strict' => true,
                            'schema' => $schema,
                        ],
                    ]
                    : ['type' => 'json_object'],
        ];
        $payload[$options['token_parameter'] ?? 'max_tokens'] =
            (int) ($options['max_tokens'] ?? 3000);
        if (($options['temperature'] ?? '') !== '') {
            $payload['temperature'] = (float) $options['temperature'];
        }
        $response = $this->http->json(
            'POST',
            rtrim($configuration['endpoint'], '/') . '/chat/completions',
            $payload,
            ['Authorization: Bearer ' . Crypto::decrypt($configuration['encrypted_key'])],
            (int) ($options['timeout'] ?? 60),
        );
        $choice = $response['choices'][0] ?? [];
        if (($choice['finish_reason'] ?? '') !== 'stop' || !empty($choice['message']['refusal'])) {
            throw new HttpException(
                'The AI response was incomplete or refused. Nothing has been applied.',
            );
        }
        try {
            $data = json_decode($choice['message']['content'] ?? '', true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException('The AI returned invalid JSON. Nothing has been applied.');
        }
        if (!is_array($data)) {
            throw new HttpException('The AI did not return a structured object.');
        }
        Schema::validate($data, $schema);
        return ['data' => $data, 'usage' => $response['usage'] ?? []];
    }
}
