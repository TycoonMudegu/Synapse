<?php

declare(strict_types=1);

namespace App\Services\Discovery;

use App\Core\HttpException;

final class PublicJobParser
{
    public function links(string $html, string $sourceKey): array
    {
        $origin = 'https://' . parse_url(SourceCatalog::get($sourceKey)['url'], PHP_URL_HOST);
        $pathPattern = match ($sourceKey) {
            'brightermonday' => '#^/listings/[a-z0-9-]+/?$#i',
            'fuzu' => '#^/kenya/jobs/[a-z0-9-]+/?$#i',
            default => throw new HttpException('Unknown public job source.'),
        };
        $links = [];
        foreach ($this->document($html)->query('//a[@href]') as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if (str_starts_with($href, '/') && !str_starts_with($href, '//')) {
                $href = $origin . $href;
            }
            $parts = parse_url($href);
            if (
                !$parts ||
                !filter_var($href, FILTER_VALIDATE_URL) ||
                ($parts['scheme'] ?? '') !== 'https' ||
                isset($parts['user']) ||
                isset($parts['pass']) ||
                ($parts['port'] ?? 443) !== 443 ||
                strlen($href) > 2048 ||
                !in_array(
                    strtolower($parts['host'] ?? ''),
                    [parse_url($origin, PHP_URL_HOST), substr(parse_url($origin, PHP_URL_HOST), 4)],
                    true,
                ) ||
                !preg_match($pathPattern, $parts['path'] ?? '')
            ) {
                continue;
            }
            // Only canonical public detail paths are fetched, never search or tracking queries.
            $url = $origin . rtrim($parts['path'], '/');
            $links[$url] = $url;
        }
        return array_values($links);
    }

    public function job(string $html, string $url): array
    {
        $nodes = [];
        foreach ($this->document($html)->query('//script[@type]') as $script) {
            if (
                strtolower(trim(explode(';', $script->getAttribute('type'))[0])) !==
                'application/ld+json'
            ) {
                continue;
            }
            try {
                $json = json_decode($script->textContent, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }
            if (is_array($json)) {
                $this->flatten($json, $nodes);
            }
        }
        $index = [];
        $postings = [];
        $mainEntity = null;
        foreach ($nodes as $node) {
            if (is_string($node['@id'] ?? null)) {
                $index[$node['@id']] = array_replace($index[$node['@id']] ?? [], $node);
            }
            $types = (array) ($node['@type'] ?? []);
            if (in_array('JobPosting', $types, true)) {
                $postings[] = $node;
            }
            if (
                in_array('WebPage', $types, true) &&
                is_string($node['url'] ?? null) &&
                rtrim($node['url'] ?? '', '/') === rtrim($url, '/')
            ) {
                $mainEntity = $node['mainEntity']['@id'] ?? null;
            }
        }
        if ($mainEntity !== null) {
            $postings = array_values(
                array_filter(
                    $postings,
                    fn(array $node): bool => ($node['@id'] ?? null) === $mainEntity,
                ),
            );
        } elseif (count($postings) > 1) {
            $postings = array_values(
                array_filter(
                    $postings,
                    fn(array $node): bool => is_string($node['url'] ?? null) &&
                        rtrim($node['url'], '/') === rtrim($url, '/'),
                ),
            );
        }
        if (count($postings) !== 1) {
            throw new HttpException('The page does not identify one structured vacancy.');
        }
        $posting = $postings[0];
        $organisation = $this->resolve($posting['hiringOrganization'] ?? [], $index);
        $title = $this->text($posting['title'] ?? '');
        $employer = $this->text($organisation['name'] ?? ($organisation['legalName'] ?? ''));
        $description = $this->text($posting['description'] ?? '');
        if ($title === '' || $employer === '' || $description === '') {
            throw new HttpException(
                'The page is missing an explicit job title, employer or description.',
            );
        }
        $locations = $posting['jobLocation'] ?? [];
        $locations = is_array($locations) ? $locations : [];
        $locations = array_is_list($locations) ? $locations : [$locations];
        $places = [];
        foreach ($locations as $location) {
            $place = $this->resolve($location, $index);
            $address = $this->resolve($place['address'] ?? [], $index);
            $country = $address['addressCountry'] ?? '';
            if (is_array($country)) {
                $country = $this->resolve($country, $index)['name'] ?? '';
            }
            $country = $country === 'KE' ? 'Kenya' : $country;
            $parts = array_filter(
                array_map($this->text(...), [
                    $address['addressLocality'] ?? '',
                    $address['addressRegion'] ?? '',
                    $country,
                ]),
            );
            if ($parts !== []) {
                $places[] = implode(', ', array_unique($parts));
            }
        }
        $deadline = null;
        if (!empty($posting['validThrough'])) {
            $date = $posting['validThrough'];
            if (
                !is_string($date) ||
                !preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T.*)?$/', $date, $match) ||
                !checkdate((int) $match[2], (int) $match[3], (int) $match[1])
            ) {
                throw new HttpException('The page contains an invalid advertised deadline.');
            }
            $deadline = substr($date, 0, 10);
        }
        $employment = 'not_specified';
        foreach ((array) ($posting['employmentType'] ?? []) as $type) {
            $employment = match ($type) {
                'FULL_TIME' => 'full_time',
                'PART_TIME' => 'part_time',
                'CONTRACTOR' => 'contract',
                'TEMPORARY' => 'temporary',
                'INTERN' => 'internship',
                'VOLUNTEER' => 'volunteer',
                default => 'not_specified',
            };
            if ($employment !== 'not_specified') {
                break;
            }
        }
        return [
            'title' => $title,
            'employer' => $employer,
            'description' => mb_substr($description, 0, 60000),
            'location' => implode('; ', array_unique($places)),
            'industry' => $this->text($posting['industry'] ?? ''),
            'deadline' => $deadline,
            'employment_type' => $employment,
            'workplace' =>
                ($posting['jobLocationType'] ?? '') === 'TELECOMMUTE' ? 'remote' : 'not_specified',
            'original_url' => $url,
            'application_method' => 'external',
        ];
    }

    private function document(string $html): \DOMXPath
    {
        if (strlen($html) > 4194304) {
            throw new HttpException('The public page exceeds the collection size limit.');
        }
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML(
                '<?xml encoding="UTF-8">' . $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new \DOMXPath($document);
    }

    private function flatten(array $value, array &$nodes): void
    {
        if (isset($value['@type']) || isset($value['@id'])) {
            $nodes[] = $value;
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $this->flatten($child, $nodes);
            }
        }
    }

    private function resolve(mixed $value, array $index): array
    {
        if (!is_array($value)) {
            return [];
        }
        $id = is_string($value['@id'] ?? null) ? $value['@id'] : '';
        return array_replace_recursive($index[$id] ?? [], $value);
    }

    private function text(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = preg_replace('#</?(?:p|br|div|li|ul|ol|h[1-6])(?:\s[^>]*)?>#i', "\n", $value);
        return (new PublicContent())->text($value);
    }
}
