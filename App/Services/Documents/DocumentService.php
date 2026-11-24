<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Core\Env;
use App\Core\HttpException;
use App\Repositories\ActivityRepository;
use App\Repositories\DocumentRepository;
use App\Services\AI\AiService;
use Smalot\PdfParser\Parser;
use ZipArchive;

final class DocumentService
{
    private const TYPES = [
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function upload(int $userId, array $file, string $kind): array
    {
        if (DocumentStorage::enabled()) {
            throw new HttpException(
                'Upload documents directly to cloud storage using the upload form.',
                409,
            );
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new HttpException(
                'The upload failed. Choose a file within the server upload limit.',
            );
        }
        $maxBytes = min(20, max(1, (int) Env::get('UPLOAD_MAX_MB', '10'))) * 1024 * 1024;
        if (
            ($file['size'] ?? 0) <= 0 ||
            $file['size'] > $maxBytes ||
            !is_uploaded_file($file['tmp_name'])
        ) {
            throw new HttpException(
                'Upload a non-empty file smaller than ' . $maxBytes / 1048576 . ' MB.',
            );
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (
            $mime === 'application/zip' &&
            strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) === 'docx'
        ) {
            $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        }
        if (
            !isset(self::TYPES[$mime]) ||
            (in_array($kind, ['master_cv', 'cover_letter_template'], true) &&
                !in_array(self::TYPES[$mime], ['pdf', 'docx'], true)) ||
            ($kind === 'job_poster' && self::TYPES[$mime] === 'docx')
        ) {
            throw new HttpException(
                'Choose a supported PDF, DOCX, JPG, PNG or WEBP file. CVs must be PDF or DOCX.',
            );
        }
        if (str_starts_with($mime, 'image/')) {
            $dimensions = getimagesize($file['tmp_name']);
            if (!$dimensions || $dimensions[0] * $dimensions[1] > 25000000) {
                throw new HttpException('The image is invalid or exceeds 25 megapixels.');
            }
        }
        if (self::TYPES[$mime] === 'docx') {
            $this->docxText($file['tmp_name']);
        }
        $name = basename(str_replace('\\', '/', $file['name']));
        $name = mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', '', $name), 0, 240);
        $path = bin2hex(random_bytes(24)) . '.' . self::TYPES[$mime];
        $absolute = BASE_PATH . '/storage/private/' . $path;
        if (!move_uploaded_file($file['tmp_name'], $absolute)) {
            throw new HttpException(
                'The file could not be stored. Check storage permissions.',
                500,
            );
        }
        PrivateFiles::protect($absolute);
        $text = '';
        try {
            if ($mime === 'application/pdf') {
                $text = (new Parser())->parseFile($absolute)->getText();
            } elseif (self::TYPES[$mime] === 'docx') {
                $text = $this->docxText($absolute);
            }
        } catch (\Throwable) {
            // Preserve the original and explicitly offer manual text entry if extraction fails.
            $text = '';
        }
        $repository = new DocumentRepository();
        try {
            $id = $repository->create($userId, [
                'name' => $name,
                'kind' => $kind,
                'storage_path' => $path,
                'mime_type' => $mime,
                'byte_size' => filesize($absolute),
                'extracted_text' => mb_substr($text, 0, 100000),
            ]);
            if ($kind === 'master_cv') {
                $repository->addCv($userId, $id);
            }
        } catch (\Throwable $exception) {
            unlink($absolute);
            throw $exception;
        }
        (new ActivityRepository())->log(
            $userId,
            'document.uploaded',
            'Uploaded a private ' . str_replace('_', ' ', $kind) . ' document.',
        );
        return $repository->get($userId, $id);
    }

    public function extractPoster(int $userId, int $documentId): array
    {
        $document = (new DocumentRepository())->get($userId, $documentId);
        DocumentStorage::assertAvailable($document);
        if (($document['processing_status'] ?? 'ready') !== 'ready') {
            $this->process($userId, $documentId);
            $document = (new DocumentRepository())->get($userId, $documentId);
        }
        $image = null;
        if (str_starts_with($document['mime_type'], 'image/')) {
            $image =
                'data:' .
                $document['mime_type'] .
                ';base64,' .
                base64_encode((new DocumentStorage())->read($document));
        } elseif (trim($document['extracted_text'] ?? '') === '') {
            throw new HttpException(
                'No text could be extracted from this PDF. Upload an image of the poster with a vision-capable model, or paste its text. The original file is saved.',
            );
        }
        return (new AiService())->run(
            $userId,
            'poster',
            ['advertisement' => $document['extracted_text'] ?? 'Read the attached poster.'],
            image: $image,
        );
    }

    public function profileDraft(int $userId, int $documentId): array
    {
        $document = (new DocumentRepository())->get($userId, $documentId);
        DocumentStorage::assertAvailable($document);
        if (($document['processing_status'] ?? 'ready') !== 'ready') {
            $this->process($userId, $documentId);
            $document = (new DocumentRepository())->get($userId, $documentId);
        }
        if (trim($document['extracted_text'] ?? '') === '') {
            throw new HttpException(
                'No readable CV text was extracted. You can enter your career profile manually.',
            );
        }
        return (new AiService())->run($userId, 'profile', [
            'cv_text' => $document['extracted_text'],
        ]);
    }

    public function path(array $document): string
    {
        DocumentStorage::assertAvailable($document);
        if (DocumentStorage::isCloud($document)) {
            throw new HttpException('This document is stored in Google Cloud Storage.', 409);
        }
        $filename = $document['storage_path'];
        if (!preg_match('/^[a-f0-9]{48}\.[a-z0-9]+$/', $filename)) {
            throw new HttpException('Invalid document storage path.', 500);
        }
        $path = BASE_PATH . '/storage/private/' . $filename;
        if (!is_file($path)) {
            throw new HttpException('The stored file is missing.', 404);
        }
        return $path;
    }

    public function process(int $userId, int $documentId): void
    {
        $repository = new DocumentRepository();
        $document = $repository->get($userId, $documentId);
        if ($document['processing_status'] !== 'ready') {
            $bytes = (new DocumentStorage())->read($document, true);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
            $expected = $document['mime_type'];
            $text = '';
            try {
                if (
                    $expected === RendererClient::DOCX &&
                    in_array($mime, ['application/zip', RendererClient::DOCX], true)
                ) {
                    $temporary = tempnam(sys_get_temp_dir(), 'synapse-extract-');
                    try {
                        if (
                            $temporary === false ||
                            file_put_contents($temporary, $bytes) !== strlen($bytes)
                        ) {
                            throw new HttpException(
                                'The document worker could not read the DOCX.',
                                503,
                            );
                        }
                        chmod($temporary, 0600);
                        $text = $this->docxText($temporary);
                    } finally {
                        if ($temporary !== false && is_file($temporary)) {
                            unlink($temporary);
                        }
                    }
                } elseif ($mime !== $expected) {
                    throw new HttpException(
                        'The uploaded contents do not match the document format.',
                    );
                } elseif (str_starts_with($mime, 'image/')) {
                    $size = getimagesizefromstring($bytes);
                    if (!$size || $size[0] * $size[1] > 25000000) {
                        throw new HttpException('The image is invalid or exceeds 25 megapixels.');
                    }
                } elseif ($mime === 'application/pdf') {
                    try {
                        $text = (new Parser())->parseContent($bytes)->getText();
                    } catch (\Throwable) {
                        $text = '';
                    }
                }
            } catch (HttpException $error) {
                $query = \App\Core\Database::connection()->prepare(
                    "UPDATE documents SET processing_status = 'failed' WHERE id = ? AND user_id = ?",
                );
                $query->execute([$documentId, $userId]);
                throw $error;
            }
            $repository->updateText($userId, $documentId, mb_substr($text, 0, 100000));
            \App\Core\Database::connection()
                ->prepare(
                    "UPDATE documents SET processing_status = 'ready' WHERE id = ? AND user_id = ?",
                )
                ->execute([$documentId, $userId]);
        }
        $accounts = new \App\Repositories\AccountRepository();
        $preferences = $accounts->preferences($userId);
        if (
            (int) ($preferences['cv_document_id'] ?? 0) === $documentId &&
            !$accounts->review($userId, $documentId, $preferences['field_key'])
        ) {
            (new CvReviewService())->grade($userId, $documentId);
        }
    }

    public function docxText(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true || $zip->numFiles > 1000) {
            throw new HttpException('The DOCX file is invalid.');
        }
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index);
            $total += (int) ($stat['size'] ?? 0);
            if (
                $total > 20971520 ||
                str_contains($stat['name'], '..') ||
                str_contains($stat['name'], 'vbaProject')
            ) {
                $zip->close();
                throw new HttpException(
                    'The DOCX archive exceeds safe limits or contains active content.',
                );
            }
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false || str_contains($xml, '<!DOCTYPE') || str_contains($xml, '<!ENTITY')) {
            throw new HttpException('The file is not a supported DOCX document.');
        }
        $document = new \DOMDocument();
        if (!$document->loadXML($xml, LIBXML_NONET)) {
            throw new HttpException('The DOCX document contains invalid XML.');
        }
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace(
            'w',
            'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
        );
        $lines = [];
        foreach ($xpath->query('//w:p') as $paragraph) {
            $lines[] = $paragraph->textContent;
        }
        return implode("\n", $lines);
    }
}
