<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Repositories\AccountRepository;
use App\Repositories\SettingsRepository;

final class Entitlements
{
    public function paid(int $userId): bool
    {
        return (new AccountRepository())->membership($userId)['active'];
    }

    public function requirePaid(int $userId): void
    {
        if (!$this->paid($userId)) {
            throw new HttpException(
                'Unlock CV improvements and automation with Synapse Plus. Choose a plan on the Plans page.',
                402,
            );
        }
    }

    public function requireAi(int $userId, ?int $providerId = null): void
    {
        if ($this->paid($userId)) {
            return;
        }
        $settings = new SettingsRepository();
        $id = $providerId ?? (int) $settings->get($userId, 'ai')['screening_provider'];
        $provider = $settings->provider($userId, $id);
        if (!$provider || (json_decode($provider['options_json'], true)['managed'] ?? false)) {
            throw new HttpException(
                'Add your own AI key or choose Synapse Plus to use AI features.',
                402,
            );
        }
    }
}
