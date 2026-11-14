<?php

declare(strict_types=1);

use App\Services\Security\Csrf;

function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_base_path(): string
{
    return \App\Core\Url::basePath();
}

function app_url(string $path = '/'): string
{
    return \App\Core\Url::to($path);
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(Csrf::token()) . '">';
}

function icon(string $name, string $class = ''): string
{
    return '<i data-lucide="' .
        e($name) .
        '" class="icon ' .
        e($class) .
        '" aria-hidden="true"></i>';
}

function label(string $value): string
{
    return ucfirst(str_replace('_', ' ', $value));
}

function short_date(?string $date): string
{
    return $date ? (new DateTimeImmutable($date))->format('d M Y') : 'Not supplied';
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    return mb_strtoupper(mb_substr($parts[0] ?? 'J', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
}

function empty_state(
    string $iconName,
    string $title,
    string $description,
    ?string $href = null,
    ?string $action = null,
): void {
    require BASE_PATH . '/Resources/Views/components/empty.php';
}
