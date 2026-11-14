<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Core\HttpException;

final class SourceCatalog
{
    public const SOURCES = [
        'myjobmag' => [
            'name' => 'MyJobMag Kenya',
            'adapter' => 'myjobmag',
            'url' => 'https://www.myjobmag.co.ke/jobsxml_by_categories.xml',
            'setup_url' => 'https://www.myjobmag.co.ke/feeds/',
            'description' =>
                'Import the official detailed RSS feed. No AI key or email account is needed.',
        ],
        'brightermonday' => [
            'name' => 'BrighterMonday Kenya',
            'adapter' => 'email_alert',
            'url' => 'https://www.brightermonday.co.ke/job-seeker',
            'setup_url' => 'https://www.brightermonday.co.ke/job-seeker',
            'sender_domain' => 'brightermonday.co.ke',
            'description' =>
                'Enable job alerts in your BrighterMonday account using the Gmail address you connect to JobFlow.',
        ],
        'fuzu' => [
            'name' => 'Fuzu',
            'adapter' => 'email_alert',
            'url' => 'https://www.fuzu.com/kenya/job',
            'setup_url' => 'https://www.fuzu.com/kenya/job',
            'sender_domain' => 'fuzu.com',
            'description' =>
                'Join or sign in to Fuzu, set your Kenya job preferences and enable email recommendations.',
        ],
    ];

    public static function get(string $key): array
    {
        return self::SOURCES[$key] ?? throw new HttpException('Unknown job source.');
    }

    public static function gmailQuery(array $sources): string
    {
        $queries = [];
        foreach ($sources as $source) {
            $definition = self::get($source['preset_key']);
            if (isset($definition['sender_domain'])) {
                $queries[] = 'from:(' . $definition['sender_domain'] . ')';
            }
        }
        return $queries ? '{' . implode(' ', array_unique($queries)) . '}' : '';
    }

    public static function senderMatches(string $from, string $key): bool
    {
        if (preg_match('/<([^<>]+)>/', $from, $matches)) {
            $from = $matches[1];
        }
        $address = trim($from);
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $domain = strtolower(substr(strrchr($address, '@'), 1));
        $expected = self::get($key)['sender_domain'] ?? '';
        return $expected !== '' &&
            ($domain === $expected || str_ends_with($domain, '.' . $expected));
    }
}
