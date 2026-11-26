<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Core\Env;
use App\Core\HttpException;
use App\Http\HttpClient;
use Google\Auth\ApplicationDefaultCredentials;

final class CloudRunCollector
{
    public function __construct(
        private readonly HttpClient $http = new HttpClient(),
        private readonly ?\Closure $accessToken = null,
    ) {}

    public function configured(): bool
    {
        return Env::get('COLLECTOR_PROJECT') !== '' &&
            Env::get('COLLECTOR_REGION') !== '' &&
            Env::get('COLLECTOR_JOB') !== '' &&
            strlen(Env::get('COLLECTOR_TOKEN')) >= 32 &&
            !preg_match('/\s/', Env::get('COLLECTOR_TOKEN'));
    }

    public function dispatch(int $runId): void
    {
        if (!$this->configured()) {
            throw new HttpException(
                'Configure the Cloud Run collector before triggering collection.',
            );
        }
        $project = Env::get('COLLECTOR_PROJECT');
        $region = Env::get('COLLECTOR_REGION');
        $job = Env::get('COLLECTOR_JOB');
        foreach ([$project, $region, $job] as $value) {
            if (!preg_match('/^[a-z][a-z0-9-]{0,62}$/D', $value)) {
                throw new HttpException('Invalid collector deployment configuration.');
            }
        }
        try {
            if ($this->accessToken !== null) {
                $token = ($this->accessToken)();
            } else {
                $credentials = ApplicationDefaultCredentials::getCredentials([
                    'https://www.googleapis.com/auth/cloud-platform',
                ]);
                $handler = \Google\Auth\HttpHandler\HttpHandlerFactory::build(
                    new \GuzzleHttp\Client(['timeout' => 10, 'connect_timeout' => 5]),
                );
                $token = $credentials->fetchAuthToken($handler)['access_token'] ?? '';
            }
            if ($token === '') {
                throw new \RuntimeException('No access token.');
            }
            $this->http->json(
                'POST',
                'https://run.googleapis.com/v2/projects/' .
                    $project .
                    '/locations/' .
                    $region .
                    '/jobs/' .
                    $job .
                    ':run',
                [
                    'overrides' => [
                        'containerOverrides' => [
                            ['env' => [['name' => 'COLLECTOR_RUN_ID', 'value' => (string) $runId]]],
                        ],
                    ],
                ],
                ['Authorization: Bearer ' . $token],
                20,
            );
        } catch (\Throwable) {
            throw new HttpException(
                'Cloud Run execution could not be confirmed. Check its execution history before retrying.',
                502,
            );
        }
    }
}
