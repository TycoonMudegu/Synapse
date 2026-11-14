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
            'adapter' => 'brightermonday_public',
            'url' => 'https://www.brightermonday.co.ke/jobs/software-data',
            'setup_url' => 'https://www.brightermonday.co.ke/job-seeker',
            'sender_domain' => 'brightermonday.co.ke',
            'description' =>
                'Collect public Software & Data vacancies directly. No Gmail or AI key is required.',
        ],
        'fuzu' => [
            'name' => 'Fuzu',
            'adapter' => 'fuzu_public',
            'url' => 'https://www.fuzu.com/kenya/job/computers-software-development',
            'setup_url' => 'https://www.fuzu.com/kenya/job',
            'sender_domain' => 'fuzu.com',
            'description' =>
                'Collect public IT and software vacancies directly when the site accepts requests from this server. No Gmail or AI key is required.',
        ],
    ];

    public static function get(string $key, string $mode = 'public'): array
    {
        $definition = self::SOURCES[$key] ?? throw new HttpException('Unknown job source.');
        if ($mode === 'email' && isset($definition['sender_domain'])) {
            $definition['adapter'] = 'email_alert';
            $definition['url'] = $definition['setup_url'];
            $definition['description'] =
                'Import the site’s subscribed email alerts through connected Gmail monitoring. AI screening is required to extract vacancies.';
        } elseif ($mode !== 'public') {
            throw new HttpException('Unsupported collection mode.');
        }
        return $definition;
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
