<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Core\Env;
use App\Core\HttpException;
use App\Http\HttpClient;

final class RendererClient
{
    public const DOCX = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function __construct(private readonly HttpClient $http = new HttpClient()) {}

    public function configured(): bool
    {
        $url = parse_url(Env::get('DOCUMENT_RENDERER_URL'));
        $token = Env::get('DOCUMENT_RENDERER_TOKEN');
        return $url !== false &&
            ($url['scheme'] ?? '') === 'https' &&
            !empty($url['host']) &&
            !isset($url['user']) &&
            !isset($url['pass']) &&
            !isset($url['query']) &&
            !isset($url['fragment']) &&
            ($url['port'] ?? 443) === 443 &&
            strlen($token) >= 32 &&
            !preg_match('/[\s\x00-\x1f]/', $token);
    }

    public function inspect(string $docx): array
    {
        $response = $this->request('/v1/inspect-docx', ['document' => base64_encode($docx)]);
        $result = json_decode($response['body'], true);
        if (
            !is_array($result) ||
            !is_array($result['paragraphs'] ?? null) ||
            !hash_equals(hash('sha256', $docx), (string) ($result['sha256'] ?? ''))
        ) {
            throw new HttpException(
                'The document renderer returned an invalid template inspection.',
                502,
            );
        }
        foreach ($result['paragraphs'] as $paragraph) {
            if (
                !is_array($paragraph) ||
                !is_string($paragraph['part'] ?? null) ||
                !preg_match('#^word/(?:document|header\d+|footer\d+)\.xml$#', $paragraph['part']) ||
                !is_int($paragraph['index'] ?? null) ||
                $paragraph['index'] < 0 ||
                !is_string($paragraph['text'] ?? null) ||
                !is_bool($paragraph['editable'] ?? null)
            ) {
                throw new HttpException(
                    'The document renderer returned invalid paragraph data.',
                    502,
                );
            }
        }
        return $result;
    }

    public function docx(string $source, array $edits, string $format): string
    {
        if (!in_array($format, ['pdf', 'docx'], true)) {
            throw new HttpException('Choose PDF or DOCX.');
        }
        return $this->render(
            '/v1/render-docx',
            ['document' => base64_encode($source), 'edits' => $edits, 'format' => $format],
            $format,
        );
    }

    public function html(string $html): string
    {
        return $this->render('/v1/render-html', ['html' => $html], 'pdf');
    }

    private function render(string $path, array $payload, string $format): string
    {
        $response = $this->request($path, $payload);
        $mime = explode(';', $response['headers']['content-type'] ?? '')[0];
        $expected = $format === 'pdf' ? 'application/pdf' : self::DOCX;
        if (
            $mime !== $expected ||
            !str_starts_with($response['body'], $format === 'pdf' ? '%PDF-' : 'PK')
        ) {
            throw new HttpException(
                'The renderer did not return the requested document format.',
                502,
            );
        }
        return $response['body'];
    }

    private function request(string $path, array $payload): array
    {
        if (!$this->configured()) {
            throw new HttpException(
                'The site owner needs to finish document rendering setup before documents can be edited while keeping their design.',
                503,
            );
        }
        $response = $this->http->request(
            'POST',
            rtrim(Env::get('DOCUMENT_RENDERER_URL'), '/') . $path,
            [
                'Content-Type: application/json',
                'Authorization: Bearer ' . Env::get('DOCUMENT_RENDERER_TOKEN'),
            ],
            json_encode($payload, JSON_THROW_ON_ERROR),
            70,
            20 * 1024 * 1024,
        );
        if ($response['status'] !== 200) {
            $code = json_decode($response['body'], true)['error'] ?? '';
            $message = match ($code) {
                'layout_overflow'
                    => 'The revised text adds pages to your design. Shorten the text or adjust the original DOCX before preparing again.',
                'complex_paragraph'
                    => 'This template has a field, drawing or line break that needs to be edited in Word first.',
                'invalid_docx'
                    => 'The source DOCX is unsupported or contains active or externally linked content. Save a standard DOCX and upload it again.',
                'stale_template' => 'The source document changed. Prepare the application again.',
                'busy' => 'Document rendering is busy. It will be retried shortly.',
                default
                    => 'Document rendering failed. The site owner needs to check the renderer connection and configuration.',
            };
            throw new HttpException(
                $message,
                $response['status'] === 429 ? 429 : ($code === 'layout_overflow' ? 409 : 502),
            );
        }
        return $response;
    }
}
