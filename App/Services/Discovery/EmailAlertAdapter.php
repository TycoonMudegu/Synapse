<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Core\HttpException;
use App\Repositories\AlertRepository;
use App\Repositories\IntegrationRepository;

final class EmailAlertAdapter implements SourceAdapter
{
    public function identifier(): string
    {
        return 'email_alert';
    }

    public function validate(array $source): void
    {
        $connection = (new IntegrationRepository())->connection((int) $source['user_id']);
        if (!$connection) {
            throw new HttpException(
                'Connect Gmail and enable this site’s job alerts using the same email address.',
            );
        }
        if (!str_contains($connection['scopes'], 'gmail.readonly')) {
            throw new HttpException('Reconnect Gmail with read-only job-alert monitoring enabled.');
        }
        if (!$connection['last_sync_at']) {
            throw new HttpException(
                'Synchronise Gmail successfully before activating this email source.',
            );
        }
    }

    public function collect(array $source, int $limit): array
    {
        $this->validate($source);
        return array_map(
            static fn(array $item) => [
                'text' => $item['advertisement'],
                'url' => $item['original_url'],
                'alert_item_id' => (int) $item['id'],
            ],
            (new AlertRepository())->pending((int) $source['user_id'], (int) $source['id'], $limit),
        );
    }
}
