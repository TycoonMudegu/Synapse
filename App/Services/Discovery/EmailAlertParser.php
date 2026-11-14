<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Services\Security\UrlGuard;

final class EmailAlertParser
{
    public function urls(string $text, string $sourceKey): array
    {
        preg_match_all(
            '~https://[^\s<>"()]+~iu',
            html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
            $matches,
        );
        $urls = [];
        foreach ($matches[0] as $candidate) {
            $candidate = rtrim($candidate, '.,;');
            $parts = parse_url($candidate);
            if (
                !$parts ||
                ($parts['scheme'] ?? '') !== 'https' ||
                isset($parts['user']) ||
                isset($parts['pass']) ||
                ($parts['port'] ?? 443) !== 443 ||
                strlen($candidate) > 2048
            ) {
                continue;
            }
            $host = strtolower($parts['host'] ?? '');
            $path = $parts['path'] ?? '/';
            $valid = match ($sourceKey) {
                'brightermonday' => in_array(
                    $host,
                    ['brightermonday.co.ke', 'www.brightermonday.co.ke'],
                    true,
                ) && (bool) preg_match('#^/listings/[^/]+/?$#', $path),
                'fuzu' => in_array($host, ['fuzu.com', 'www.fuzu.com'], true) &&
                    $this->fuzuJob($parts),
                default => false,
            };
            if ($valid) {
                $canonical = UrlGuard::canonical($candidate);
                $urls[$canonical] = $canonical;
            }
        }
        return array_values($urls);
    }

    private function fuzuJob(array $parts): bool
    {
        parse_str($parts['query'] ?? '', $query);
        return (bool) preg_match('#^/(?:[a-z]{2}/|kenya/)?jobs/[^/]+#', $parts['path'] ?? '') ||
            (isset($query['filters']['job_id']) &&
                ctype_digit((string) $query['filters']['job_id']));
    }

    public function htmlToText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        foreach ($xpath->query('//script|//style|//noscript') as $node) {
            $node->parentNode?->removeChild($node);
        }
        foreach ($xpath->query('//a[@href]') as $anchor) {
            $href = $anchor->getAttribute('href');
            if (str_starts_with($href, 'https://')) {
                $anchor->appendChild($document->createTextNode(' ' . $href . ' '));
            }
        }
        return trim(html_entity_decode($document->textContent, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
