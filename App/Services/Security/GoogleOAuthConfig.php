<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\Env;

final class GoogleOAuthConfig
{
    public static function clientId(): string
    {
        return self::credential('GOOGLE_CLIENT_ID', 'client_id');
    }

    public static function clientSecret(): string
    {
        return self::credential('GOOGLE_CLIENT_SECRET', 'client_secret');
    }

    private static function credential(string $environmentKey, string $jsonKey): string
    {
        $explicit = Env::get($environmentKey);
        if ($explicit !== '') {
            return $explicit;
        }
        // Accept inline JSON or the server-side path to the downloaded Web client.
        $json = trim(Env::get('GOOGLE_OAUTH_JSON'));
        if ($json !== '' && !str_starts_with($json, '{') && is_file($json) && is_readable($json)) {
            $json = file_get_contents($json) ?: '';
        }
        // Read only credentials from a downloaded Web application OAuth JSON.
        // Authentication endpoints and redirect URLs remain controlled by the app.
        $configuration = json_decode($json, true);
        $value = is_array($configuration) ? $configuration['web'][$jsonKey] ?? '' : '';
        return is_string($value) ? trim($value) : '';
    }
}
