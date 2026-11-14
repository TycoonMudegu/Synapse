<?php

declare(strict_types=1);

namespace App\Services\Discovery;

final class KeywordMatcher
{
    public static function contains(string $text, string $keyword): bool
    {
        $normalise = static fn(string $value): string => trim(
            preg_replace('/[\s\p{Pd}]+/u', ' ', mb_strtolower($value)),
        );
        $keyword = $normalise($keyword);
        return $keyword !== '' &&
            preg_match(
                '/(?<![\p{L}\p{N}_])' . preg_quote($keyword, '/') . '(?![\p{L}\p{N}_])/u',
                $normalise($text),
            ) === 1;
    }

    public static function any(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (self::contains($text, $keyword)) {
                return true;
            }
        }
        return false;
    }
}
