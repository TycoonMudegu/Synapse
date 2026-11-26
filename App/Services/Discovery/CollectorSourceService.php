<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Core\HttpException;
use App\Repositories\CollectorRepository;
use App\Services\Security\UrlGuard;
use App\Services\Security\Validator;

final class CollectorSourceService
{
    public function save(?int $id, array $input): int
    {
        $repository = new CollectorRepository();
        $existing = $id !== null ? $repository->source($id) : null;
        $builtin = $existing && isset(SourceCatalog::SOURCES[$existing['source_key']]);
        $adapter = $builtin
            ? $existing['adapter']
            : Validator::choice($input['adapter'] ?? '', 'source type', ['rss', 'json']);
        $url = $builtin
            ? $existing['url']
            : Validator::text($input['url'] ?? '', 'feed URL', 2048, true);
        UrlGuard::validate($url);
        $permission = filter_var($input['permission_confirmed'] ?? false, FILTER_VALIDATE_BOOL);
        $enabled = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOL);
        if ($enabled && !$permission) {
            throw new HttpException(
                'Confirm that this source permits collection before enabling it.',
            );
        }
        $config = [
            'query' => Validator::text(
                $input['query'] ?? 'IT jobs in Kenya',
                'search query',
                250,
                true,
            ),
            'location' => Validator::text(
                $input['location'] ?? 'Kenya',
                'search location',
                200,
                true,
            ),
        ];
        return $repository->saveSource($id, [
            'name' => Validator::text($input['name'] ?? '', 'source name', 200, true),
            'adapter' => $adapter,
            'url' => $url,
            'config_json' => json_encode($config, JSON_THROW_ON_ERROR),
            'enabled' => (int) $enabled,
            'permission_confirmed' => (int) $permission,
            'max_jobs' => (int) Validator::number(
                $input['max_jobs'] ?? 20,
                'vacancies per run',
                1,
                $adapter === 'myjobmag' ? 100 : 20,
            ),
        ]);
    }
}
