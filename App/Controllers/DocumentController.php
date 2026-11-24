<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Http\Request;
use App\Http\Response;
use App\Repositories\DocumentRepository;
use App\Services\Documents\DocumentService;
use App\Services\Security\Auth;
use App\Services\Security\Validator;

final class DocumentController
{
    public function index(Request $request): void
    {
        $favoritesOnly = ($_GET['filter'] ?? '') === 'favorites';
        View::render('pages/documents', [
            'title' => 'Document library',
            'active' => 'documents',
            'documents' => (new DocumentRepository())->all(Auth::id(), $favoritesOnly),
            'favoritesOnly' => $favoritesOnly,
        ]);
    }

    public function show(Request $request, int $id): void
    {
        View::render('pages/document', [
            'title' => 'Document details',
            'active' => 'documents',
            'document' => (new DocumentRepository())->get(Auth::id(), $id),
            'bodyClass' => 'document-screen',
            'associations' => (new DocumentRepository())->associations(Auth::id(), $id),
            'nextVersions' => (new DocumentRepository())->nextVersions(Auth::id(), $id),
            'snapshot' => (new DocumentRepository())->cvSnapshot(Auth::id(), $id),
        ]);
    }

    public function upload(Request $request): void
    {
        $kind = Validator::choice($request->string('kind'), 'document type', [
            'master_cv',
            'cover_letter_template',
            'job_poster',
            'supporting',
        ]);
        $document = (new DocumentService())->upload(Auth::id(), $_FILES['file'] ?? [], $kind);
        Response::json([
            'ok' => true,
            'document' => [
                'id' => $document['id'],
                'name' => $document['name'],
                'has_text' => trim($document['extracted_text'] ?? '') !== '',
            ],
            'message' => 'Document stored privately.',
            'redirect' => empty($request->all()['inline']) ? '/documents/' . $document['id'] : null,
        ]);
    }

    public function beginUpload(Request $request): void
    {
        Response::json([
            'ok' => true,
            'upload' => (new \App\Services\Documents\DirectUploadService())->begin(
                Auth::id(),
                $request->all(),
            ),
        ]);
    }

    public function completeUpload(Request $request): void
    {
        $document = (new \App\Services\Documents\DirectUploadService())->complete(
            Auth::id(),
            $request->string('upload_id'),
        );
        $task =
            $document['processing_status'] === 'pending'
                ? (new \App\Repositories\QueueRepository())->enqueue(
                    Auth::id(),
                    'process_document',
                    ['document_id' => (int) $document['id']],
                )
                : null;
        Response::json([
            'ok' => true,
            'document' => [
                'id' => $document['id'],
                'name' => $document['name'],
                'has_text' => trim($document['extracted_text'] ?? '') !== '',
                'processing_status' => $document['processing_status'],
            ],
            'message' => 'Document uploaded. Text extraction will finish in the background.',
            'task_id' => $task,
            'redirect' => empty($request->all()['inline']) ? '/documents/' . $document['id'] : null,
        ]);
    }

    public function preview(Request $request, int $id): void
    {
        $repository = new DocumentRepository();
        $document = $repository->get(Auth::id(), $id);
        if ($document['mime_type'] !== \App\Services\Documents\RendererClient::DOCX) {
            throw new \App\Core\HttpException('Choose a DOCX document for PDF preview.');
        }
        $source = (new \App\Services\Documents\DocumentStorage())->read($document);
        if ($source === false || strlen($source) > 10 * 1024 * 1024) {
            throw new \App\Core\HttpException(
                'Use a source document under 10 MiB for PDF preview.',
            );
        }
        $bytes = (new \App\Services\Documents\RendererClient())->docx($source, [], 'pdf');
        $newId = (new \App\Services\Documents\Exporter())->rendered(
            Auth::id(),
            $bytes,
            'pdf',
            pathinfo($document['name'], PATHINFO_FILENAME) . ' - PDF preview',
            'supporting',
            $document['extracted_text'] ?? '',
            $id,
        );
        if (in_array($document['kind'], ['master_cv', 'tailored_cv'], true)) {
            $repository->addCv(Auth::id(), $newId, [
                'layout_mode' => 'preserve',
                'source_cv_id' => $id,
                'cv_edits' => [],
                'document_text' => $document['extracted_text'] ?? '',
            ]);
        }
        Response::json([
            'ok' => true,
            'redirect' => '/documents/' . $newId,
            'message' => 'PDF preview created from the original design.',
        ]);
    }

    public function download(Request $request, int $id): void
    {
        $document = (new DocumentRepository())->get(Auth::id(), $id);
        $preview =
            isset($_GET['preview']) &&
            in_array(
                $document['mime_type'],
                ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'],
                true,
            );
        if (\App\Services\Documents\DocumentStorage::isCloud($document)) {
            Response::redirect(
                (new \App\Services\Documents\DocumentStorage())->downloadUrl($document, $preview),
            );
        }
        $path = (new DocumentService())->path($document);
        header('Content-Type: ' . $document['mime_type']);
        header('Content-Length: ' . filesize($path));
        header(
            'Content-Disposition: ' .
                ($preview ? 'inline' : 'attachment') .
                '; filename="document-' .
                $id .
                '.' .
                pathinfo($path, PATHINFO_EXTENSION) .
                "\"; filename*=UTF-8''" .
                rawurlencode($document['name']),
        );
        if ($preview) {
            // The review page embeds this response. DENY and a CSP sandbox can
            // prevent the browser's PDF viewer from loading the private file.
            header('X-Frame-Options: SAMEORIGIN');
            header(
                "Content-Security-Policy: default-src 'none'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'",
            );
        } else {
            header("Content-Security-Policy: sandbox; default-src 'none'");
        }
        readfile($path);
    }

    public function export(Request $request, int $id): void
    {
        $repository = new DocumentRepository();
        $document = $repository->get(Auth::id(), $id);
        \App\Services\Documents\DocumentStorage::assertAvailable($document);
        $snapshot = $repository->cvSnapshot(Auth::id(), $id);
        if (!$snapshot || (!isset($snapshot['profile']) && empty($snapshot['source_cv_id']))) {
            throw new \App\Core\HttpException(
                'This document has no saved structured CV. Export your current career profile instead.',
            );
        }
        $format = Validator::choice($request->string('format'), 'document format', ['pdf', 'docx']);
        if (($snapshot['layout_mode'] ?? '') === 'preserve' && !empty($snapshot['source_cv_id'])) {
            $newId = (new \App\Services\Documents\SourceExporter())->export(
                Auth::id(),
                $repository->get(Auth::id(), (int) $snapshot['source_cv_id']),
                $format,
                pathinfo($document['name'], PATHINFO_FILENAME),
                $id,
                $snapshot,
            );
        } else {
            $newId = (new \App\Services\Documents\Exporter())->cv(
                Auth::id(),
                $snapshot['profile'],
                $format,
                pathinfo($document['name'], PATHINFO_FILENAME),
                $id,
            );
            $repository->addCv(Auth::id(), $newId, $snapshot);
        }
        Response::json([
            'ok' => true,
            'redirect' => '/documents/' . $newId,
            'message' => 'Saved CV exported without changing its facts.',
        ]);
    }

    public function rename(Request $request, int $id): void
    {
        (new DocumentRepository())->rename(
            Auth::id(),
            $id,
            Validator::text($request->string('name'), 'document name', 250, true),
        );
        Response::json(['ok' => true, 'message' => 'Document renamed.', 'reload' => true]);
    }

    public function favorite(Request $request, int $id): void
    {
        $favorite =
            Validator::choice($request->string('favorite'), 'favorite preference', ['0', '1']) ===
            '1';
        (new \App\Services\Documents\DocumentRetentionService())->favorite(
            Auth::id(),
            $id,
            $favorite,
        );
        Response::json([
            'ok' => true,
            'message' => $favorite
                ? 'Added to favorites. This document is available and protected from cold storage.'
                : 'Removed from favorites.',
            'reload' => true,
        ]);
    }

    public function delete(Request $request, int $id): void
    {
        \App\Core\Database::transaction(function () use ($id): void {
            $document = (new DocumentRepository())->delete(Auth::id(), $id);
            (new \App\Services\Documents\DocumentStorage())->delete($document);
        });
        Response::json([
            'ok' => true,
            'message' => 'Document deleted.',
            'redirect' => '/documents',
        ]);
    }

    public function master(Request $request, int $id): void
    {
        (new DocumentRepository())->master(Auth::id(), $id);
        Response::json(['ok' => true, 'message' => 'Master CV updated.', 'reload' => true]);
    }

    public function extractProfile(Request $request, int $id): void
    {
        $draft = (new DocumentService())->profileDraft(Auth::id(), $id);
        Response::json([
            'ok' => true,
            'data' => $draft,
            'message' => 'Profile draft extracted. Review it before saving.',
        ]);
    }
}
