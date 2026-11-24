<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Core\Database;
use App\Core\Env;
use App\Core\HttpException;
use App\Http\HttpClient;
use App\Repositories\UserRepository;

final class GoogleSignIn
{
    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    public static function configured(): bool
    {
        return GoogleOAuthConfig::clientId() !== '' &&
            GoogleOAuthConfig::clientSecret() !== '' &&
            Env::get('GOOGLE_SIGNIN_REDIRECT_URI') !== '';
    }

    public function authorizationUrl(): string
    {
        if (!self::configured()) {
            throw new HttpException(
                'Google sign-in is not available yet. Create an account with email.',
            );
        }
        $verifier = bin2hex(random_bytes(32));
        $_SESSION['google_signin'] = [
            'state' => bin2hex(random_bytes(32)),
            'expires' => time() + 600,
            'verifier' => $verifier,
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' .
            http_build_query([
                'client_id' => GoogleOAuthConfig::clientId(),
                'redirect_uri' => Env::get('GOOGLE_SIGNIN_REDIRECT_URI'),
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'state' => $_SESSION['google_signin']['state'],
                'prompt' => 'select_account',
                'code_challenge' => rtrim(
                    strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'),
                    '=',
                ),
                'code_challenge_method' => 'S256',
            ]);
    }

    public function callback(array $input): int
    {
        $pending = $_SESSION['google_signin'] ?? [];
        unset($_SESSION['google_signin']);
        if (
            !is_string($input['state'] ?? null) ||
            empty($pending['state']) ||
            !hash_equals($pending['state'], $input['state']) ||
            ($pending['expires'] ?? 0) < time() ||
            !is_string($input['code'] ?? null) ||
            $input['code'] === ''
        ) {
            throw new HttpException('Google sign-in expired or was cancelled. Please try again.');
        }
        $response = $this->http->request(
            'POST',
            'https://oauth2.googleapis.com/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                'grant_type' => 'authorization_code',
                'code' => $input['code'],
                'client_id' => GoogleOAuthConfig::clientId(),
                'client_secret' => GoogleOAuthConfig::clientSecret(),
                'redirect_uri' => Env::get('GOOGLE_SIGNIN_REDIRECT_URI'),
                'code_verifier' => $pending['verifier'],
            ]),
        );
        $tokens = json_decode($response['body'], true);
        if ($response['status'] !== 200 || empty($tokens['access_token'])) {
            throw new HttpException('Google could not finish signing you in. Please try again.');
        }
        $identity = $this->http->json(
            'GET',
            'https://openidconnect.googleapis.com/v1/userinfo',
            headers: ['Authorization: Bearer ' . $tokens['access_token']],
        );
        if (($identity['email_verified'] ?? false) !== true || empty($identity['sub'])) {
            throw new HttpException('Google did not return a verified email address.');
        }
        $subject = Validator::text($identity['sub'], 'Google identity', 255, true);
        $email = Validator::email($identity['email'] ?? '', true);
        $users = new UserRepository();
        $existing = $users->byGoogle($subject);
        if ($existing) {
            return (int) $existing['id'];
        }
        if ($users->byEmail($email)) {
            throw new HttpException(
                'This email already has an account. Sign in using your password.',
            );
        }
        try {
            return Database::transaction(function () use (
                $users,
                $identity,
                $email,
                $subject,
            ): int {
                $id = $users->create(
                    Validator::text($identity['name'] ?? 'Job seeker', 'name', 150, true),
                    $email,
                    bin2hex(random_bytes(32)),
                );
                $users->googleSubject($id, $subject);
                return $id;
            });
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                throw new HttpException('This account already exists. Please sign in again.');
            }
            throw $exception;
        }
    }
}
