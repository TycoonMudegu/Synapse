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
        View::render('pages/documents', [
            'title' => 'Document library',
            'active' => 'documents',
            'documents' => (new DocumentRepository())->all(Auth::id()),
        ]);
    }

    public function show(Request $request, int $id): void
    {
        View::render('pages/document', [
            'title' => 'Document details',
            'active' => 'documents',
            'document' => (new DocumentRepository())->get(Auth::id(), $id),
            'associations' => (new DocumentRepository())->associations(Auth::id(), $id),
            'nextVersions' => (new DocumentRepository())->nextVersions(Auth::id(), $id),
            'snapshot' => (new DocumentRepository())->cvSnapshot(Auth::id(), $id),
        ]);
    }

    public function upload(Request $request): void
    {
        $kind = Validator::choice($request->string('kind'), 'document type', [
            'master_cv',
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

    public function download(Request $request, int $id): void
    {
        $document = (new DocumentRepository())->get(Auth::id(), $id);
        $path = (new DocumentService())->path($document);
        $preview =
            isset($_GET['preview']) &&
            in_array(
                $document['mime_type'],
                ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'],
                true,
            );
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
        header("Content-Security-Policy: sandbox; default-src 'none'");
        readfile($path);
    }

    public function export(Request $request, int $id): void
    {
        $repository = new DocumentRepository();
        $document = $repository->get(Auth::id(), $id);
        $snapshot = $repository->cvSnapshot(Auth::id(), $id);
        if (!$snapshot || !isset($snapshot['profile'])) {
            throw new \App\Core\HttpException(
                'This document has no saved structured CV. Export your current career profile instead.',
            );
        }
        $format = Validator::choice($request->string('format'), 'document format', ['pdf', 'docx']);
        $newId = (new \App\Services\Documents\Exporter())->cv(
            Auth::id(),
            $snapshot['profile'],
            $format,
            pathinfo($document['name'], PATHINFO_FILENAME),
            $id,
        );
        $repository->addCv(Auth::id(), $newId, $snapshot);
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

    public function delete(Request $request, int $id): void
    {
        $document = (new DocumentRepository())->get(Auth::id(), $id);
        $path = (new DocumentService())->path($document);
        (new DocumentRepository())->delete(Auth::id(), $id);
        unlink($path);
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
