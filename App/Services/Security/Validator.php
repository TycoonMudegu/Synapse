<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\HttpException;

final class Validator
{
    public static function text(
        mixed $value,
        string $field,
        int $max = 250,
        bool $required = false,
    ): string {
        if (!is_scalar($value) && $value !== null) {
            throw new HttpException('Invalid ' . $field . '.');
        }
        $text = trim((string) $value);
        if ($required && $text === '') {
            throw new HttpException(ucfirst($field) . ' is required.');
        }
        if (mb_strlen($text) > $max || str_contains($text, "\0")) {
            throw new HttpException(
                ucfirst($field) . ' must contain at most ' . $max . ' characters.',
            );
        }
        return $text;
    }

    public static function email(mixed $value, bool $required = false): string
    {
        $email = self::text($value, 'email address', 254, $required);
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('Enter a valid email address.');
        }
        return $email;
    }

    public static function number(mixed $value, string $field, float $min, float $max): float
    {
        if (!is_numeric($value) || (float) $value < $min || (float) $value > $max) {
            throw new HttpException(
                ucfirst($field) . ' must be between ' . $min . ' and ' . $max . '.',
            );
        }
        return (float) $value;
    }

    public static function choice(mixed $value, string $field, array $choices): string
    {
        if (!is_string($value) || !in_array($value, $choices, true)) {
            throw new HttpException('Select a valid ' . $field . '.');
        }
        return $value;
    }

    public static function date(mixed $value, string $field): ?string
    {
        if ($value === '' || $value === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new HttpException('Enter a valid ' . $field . '.');
        }
        return $value;
    }

    public static function lines(mixed $value, int $maxItems = 100): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,\n]+/', $value);
        }
        if (!is_array($value) || count($value) > $maxItems) {
            throw new HttpException('Too many list items.');
        }
        return array_values(
            array_filter(array_map(fn($item) => self::text($item, 'list item', 150), $value)),
        );
    }
}
