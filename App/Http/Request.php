<?php

declare(strict_types=1);

namespace App\Http;

use App\Core\HttpException;

final class Request
{
    private ?array $input = null;

    public function all(): array
    {
        if ($this->input === null) {
            if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
                try {
                    $decoded = json_decode(
                        file_get_contents('php://input'),
                        true,
                        64,
                        JSON_THROW_ON_ERROR,
                    );
                } catch (\JsonException) {
                    throw new HttpException('The request contains invalid JSON.', 400);
                }
                if (!is_array($decoded) || (array_is_list($decoded) && $decoded !== [])) {
                    throw new HttpException('Expected a JSON object.', 400);
                }
                $this->input = $decoded;
            } else {
                $this->input = $_POST;
            }
        }
        return $this->input;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->all()[$key] ?? $default;
        if (!is_scalar($value)) {
            throw new HttpException('Invalid value for ' . $key . '.');
        }
        return trim((string) $value);
    }

    public function password(string $key): string
    {
        $value = $this->all()[$key] ?? '';
        if (!is_string($value)) {
            throw new HttpException('Invalid password.');
        }
        return $value;
    }

    public function wantsJson(): bool
    {
        return str_starts_with($this->path(), '/api/') ||
            str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }

    public function path(): string
    {
        return \App\Core\Url::routePath($_SERVER['REQUEST_URI'] ?? '/');
    }
}
