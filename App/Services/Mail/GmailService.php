<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Env;
use App\Core\HttpException;
use App\Http\HttpClient;
use App\Repositories\ActivityRepository;
use App\Repositories\IntegrationRepository;
use App\Repositories\SettingsRepository;
use App\Services\Documents\DocumentService;
use App\Services\Security\Crypto;

final class GmailService
{
    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';

    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    public function configured(): bool
    {
        return Env::get('GOOGLE_CLIENT_ID') !== '' &&
            Env::get('GOOGLE_CLIENT_SECRET') !== '' &&
            Env::get('GOOGLE_REDIRECT_URI') !== '';
    }

    public function authorizationUrl(int $userId): string
    {
        if (!$this->configured()) {
            throw new HttpException(
                'Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and GOOGLE_REDIRECT_URI in the server environment first.',
            );
        }
        $settings = (new SettingsRepository())->get($userId, 'gmail');
        $scopes = ['openid', 'email', 'https://www.googleapis.com/auth/gmail.send'];
        if ($settings['monitor_enabled'] || $settings['response_tracking']) {
            $scopes[] = 'https://www.googleapis.com/auth/gmail.readonly';
        }
        $_SESSION['google_oauth'] = [
            'state' => bin2hex(random_bytes(32)),
            'user_id' => $userId,
            'expires' => time() + 600,
            'scopes' => implode(' ', $scopes),
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' .
            http_build_query([
                'client_id' => Env::get('GOOGLE_CLIENT_ID'),
                'redirect_uri' => Env::get('GOOGLE_REDIRECT_URI'),
                'response_type' => 'code',
                'scope' => implode(' ', $scopes),
                'state' => $_SESSION['google_oauth']['state'],
                'access_type' => 'offline',
                'prompt' => 'consent select_account',
            ]);
    }

    public function callback(int $userId, string $state, string $code): void
    {
        $pending = $_SESSION['google_oauth'] ?? [];
        unset($_SESSION['google_oauth']);
        if (
            $state === '' ||
            !hash_equals($pending['state'] ?? '', $state) ||
            ($pending['user_id'] ?? 0) !== $userId ||
            ($pending['expires'] ?? 0) < time() ||
            $code === ''
        ) {
            throw new HttpException(
                'The Gmail connection request expired or was rejected. Connect again.',
                400,
            );
        }
        $tokens = $this->tokenRequest([
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => Env::get('GOOGLE_REDIRECT_URI'),
        ]);
        if (empty($tokens['access_token']) || empty($tokens['refresh_token'])) {
            throw new HttpException(
                'Google did not grant offline access. Reconnect and grant the requested permissions.',
            );
        }
        $scopes = $tokens['scope'] ?? '';
        if (!str_contains($scopes, 'gmail.send')) {
            throw new HttpException('Gmail sending permission was not granted.');
        }
        $identity = $this->http->json(
            'GET',
            'https://www.googleapis.com/oauth2/v3/userinfo',
            headers: ['Authorization: Bearer ' . $tokens['access_token']],
        );
        if (empty($identity['email']) || empty($identity['email_verified'])) {
            throw new HttpException('Google did not return a verified sending identity.');
        }
        $tokens['expires_at'] = time() + (int) ($tokens['expires_in'] ?? 3600);
        (new IntegrationRepository())->connect(
            $userId,
            $identity['email'],
            Crypto::encrypt(json_encode($tokens, JSON_THROW_ON_ERROR)),
            $scopes,
        );
        (new ActivityRepository())->log(
            $userId,
            'gmail.connected',
            'Connected the Gmail sending account.',
        );
    }

    private function tokenRequest(array $data): array
    {
        $response = $this->http->request(
            'POST',
            'https://oauth2.googleapis.com/token',
            ['Content-Type: application/x-www-form-urlencoded'],
            http_build_query([
                ...$data,
                'client_id' => Env::get('GOOGLE_CLIENT_ID'),
                'client_secret' => Env::get('GOOGLE_CLIENT_SECRET'),
            ]),
        );
        $result = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($result) || empty($result['access_token'])) {
            throw new HttpException(
                'Google authentication failed. Reconnect Gmail and verify the OAuth configuration.',
            );
        }
        return $result;
    }

    private function token(int $userId): string
    {
        $repository = new IntegrationRepository();
        $connection =
            $repository->connection($userId) ??
            throw new HttpException('Connect Gmail in Settings first.');
        $tokens = json_decode(
            Crypto::decrypt($connection['encrypted_tokens']),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        if (($tokens['expires_at'] ?? 0) < time() + 60) {
            $fresh = $this->tokenRequest([
                'grant_type' => 'refresh_token',
                'refresh_token' => $tokens['refresh_token'],
            ]);
            $tokens = array_replace($tokens, $fresh, [
                'expires_at' => time() + (int) ($fresh['expires_in'] ?? 3600),
            ]);
            $repository->tokens(
                $userId,
                Crypto::encrypt(json_encode($tokens, JSON_THROW_ON_ERROR)),
            );
        }
        return $tokens['access_token'];
    }

    public function disconnect(int $userId): void
    {
        $repository = new IntegrationRepository();
        $connection = $repository->connection($userId);
        $revoked = true;
        if ($connection) {
            $tokens = json_decode(
                Crypto::decrypt($connection['encrypted_tokens']),
                true,
                64,
                JSON_THROW_ON_ERROR,
            );
            try {
                $result = $this->http->request(
                    'POST',
                    'https://oauth2.googleapis.com/revoke',
                    ['Content-Type: application/x-www-form-urlencoded'],
                    http_build_query([
                        'token' => $tokens['refresh_token'] ?? $tokens['access_token'],
                    ]),
                );
                $revoked = $result['status'] === 200;
            } catch (\Throwable) {
                $revoked = false;
            }
        }
        $repository->disconnect($userId);
        (new ActivityRepository())->log(
            $userId,
            'gmail.disconnected',
            'Removed stored Gmail credentials.',
        );
        if (!$revoked) {
            throw new HttpException(
                'Gmail is disconnected locally. Google revocation failed; remove JobFlow access from your Google account permissions.',
            );
        }
    }

    public function send(
        int $userId,
        string $recipient,
        string $subject,
        string $body,
        array $documents = [],
        ?string $messageKey = null,
    ): array {
        $connection =
            (new IntegrationRepository())->connection($userId) ??
            throw new HttpException('Connect Gmail first.');
        $signature = (new SettingsRepository())->get($userId, 'gmail')['signature'];
        $mime = $this->mime(
            $connection['account_email'],
            $recipient,
            $subject,
            $body . ($signature !== '' ? "\n\n" . $signature : ''),
            $documents,
            $messageKey,
        );
        $response = $this->http->json(
            'POST',
            self::API . '/messages/send',
            ['raw' => rtrim(strtr(base64_encode($mime), '+/', '-_'), '=')],
            ['Authorization: Bearer ' . $this->token($userId)],
        );
        if (empty($response['id'])) {
            throw new HttpException(
                'Gmail did not return a submission confirmation. Check Sent before taking further action.',
            );
        }
        return $response;
    }

    public function mime(
        string $from,
        string $to,
        string $subject,
        string $body,
        array $documents,
        ?string $key,
    ): string {
        foreach ([$from, $to, $subject] as $header) {
            if (preg_match('/[\r\n\x00]/', $header)) {
                throw new HttpException('Email headers cannot contain line breaks.');
            }
        }
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new HttpException('A valid sender and recipient are required.');
        }
        $boundary = 'jobflow_' . bin2hex(random_bytes(20));
        $headers = [
            'From: ' . $from,
            'To: ' . $to,
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Message-ID: <' . ($key ?? bin2hex(random_bytes(24))) . '@jobflow.local>',
            'Content-Type: multipart/mixed; boundary="' . $boundary . '"',
        ];
        $parts = [
            implode("\r\n", $headers),
            '',
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($body)),
        ];
        foreach ($documents as $document) {
            $filename =
                'attachment-' .
                $document['id'] .
                '.' .
                pathinfo($document['storage_path'], PATHINFO_EXTENSION);
            $parts = [
                ...$parts,
                '--' . $boundary,
                'Content-Type: ' . $document['mime_type'],
                'Content-Disposition: attachment; filename="' . $filename . '"',
                'Content-Transfer-Encoding: base64',
                '',
                chunk_split(
                    base64_encode(file_get_contents((new DocumentService())->path($document))),
                ),
            ];
        }
        $parts[] = '--' . $boundary . '--';
        return implode("\r\n", $parts);
    }

    public function sync(int $userId): int
    {
        $repository = new IntegrationRepository();
        $connection =
            $repository->connection($userId) ?? throw new HttpException('Connect Gmail first.');
        if (!str_contains($connection['scopes'], 'gmail.readonly')) {
            throw new HttpException(
                'Enable monitoring and reconnect Gmail to grant read-only permission.',
            );
        }
        $settings = (new SettingsRepository())->get($userId, 'gmail');
        $headers = ['Authorization: Bearer ' . $this->token($userId)];
        $messages = [];
        if ($settings['response_tracking']) {
            foreach ($repository->threads($userId) as $thread) {
                $data = $this->http->json(
                    'GET',
                    self::API .
                        '/threads/' .
                        rawurlencode($thread['provider_thread_id']) .
                        '?format=full',
                    headers: $headers,
                );
                foreach ($data['messages'] ?? [] as $message) {
                    $messages[$message['id']] = [$message, (int) $thread['id']];
                }
            }
        }
        if ($settings['monitor_enabled'] && trim($settings['alert_query']) !== '') {
            $listing = $this->http->json(
                'GET',
                self::API .
                    '/messages?' .
                    http_build_query([
                        'q' => '(' . $settings['alert_query'] . ') newer_than:30d -in:sent',
                        'maxResults' => 50,
                    ]),
                headers: $headers,
            );
            foreach ($listing['messages'] ?? [] as $item) {
                $messages[$item['id']] = [
                    $this->http->json(
                        'GET',
                        self::API . '/messages/' . rawurlencode($item['id']) . '?format=full',
                        headers: $headers,
                    ),
                    null,
                ];
            }
        }
        $count = 0;
        foreach ($messages as [$message, $applicationId]) {
            if (in_array('SENT', $message['labelIds'] ?? [], true)) {
                continue;
            }
            $values = [];
            foreach ($message['payload']['headers'] ?? [] as $header) {
                $values[strtolower($header['name'])] = $header['value'];
            }
            $body = $this->messageBody($message['payload'] ?? []);
            $type = $applicationId ? 'response' : 'job_alert';
            if (preg_match('/\binterview\b/i', ($values['subject'] ?? '') . ' ' . $body)) {
                $type = 'interview_needs_review';
            }
            $stored = $repository->storeMessage($userId, [
                'application_id' => $applicationId,
                'gmail_id' => $message['id'],
                'thread_id' => $message['threadId'],
                'sender' => $values['from'] ?? '',
                'subject' => $values['subject'] ?? '(No subject)',
                'body' => mb_substr($body, 0, 100000),
                'snippet' => $message['snippet'] ?? '',
                'message_type' => $type,
                'has_attachments' => $this->hasAttachments($message['payload'] ?? []),
                'received_at' => date(
                    'Y-m-d H:i:s',
                    (int) ($message['internalDate'] ?? time() * 1000) / 1000,
                ),
            ]);
            if ($stored) {
                $count++;
                (new ActivityRepository())->notify(
                    $userId,
                    'email',
                    $type === 'interview_needs_review'
                        ? 'Possible interview invitation'
                        : 'New application-related email',
                    mb_substr($values['subject'] ?? '', 0, 200),
                    '/inbox',
                );
            }
        }
        $repository->syncTime($userId);
        return $count;
    }

    private function messageBody(array $part): string
    {
        $content = '';
        if (
            isset($part['body']['data']) &&
            in_array($part['mimeType'] ?? '', ['text/plain', 'text/html'], true)
        ) {
            $decoded = base64_decode(strtr($part['body']['data'], '-_', '+/')) ?: '';
            $content =
                $part['mimeType'] === 'text/html'
                    ? html_entity_decode(strip_tags($decoded), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                    : $decoded;
        }
        foreach ($part['parts'] ?? [] as $child) {
            if (($child['mimeType'] ?? '') === 'text/plain') {
                return $this->messageBody($child);
            }
            $content .= "\n" . $this->messageBody($child);
        }
        return trim($content);
    }

    private function hasAttachments(array $part): bool
    {
        if (!empty($part['filename'])) {
            return true;
        }
        foreach ($part['parts'] ?? [] as $child) {
            if ($this->hasAttachments($child)) {
                return true;
            }
        }
        return false;
    }
}
