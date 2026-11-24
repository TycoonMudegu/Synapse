<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Core\HttpException;
use App\Repositories\AccountRepository;
use App\Repositories\DocumentRepository;

final class SourceExporter
{
    public function __construct(private readonly RendererClient $renderer = new RendererClient()) {}

    public function master(int $userId): array
    {
        $repository = new DocumentRepository();
        foreach ($repository->all($userId) as $document) {
            if ($document['is_master']) {
                return $document;
            }
        }
        $id = (new AccountRepository())->preferences($userId)['cv_document_id'];
        if (!$id) {
            throw new HttpException('Upload a CV and choose it as your master CV first.');
        }
        return $repository->get($userId, (int) $id);
    }

    public function export(
        int $userId,
        array $source,
        string $format,
        string $name,
        ?int $parentId = null,
        array $snapshot = [],
    ): int {
        $repository = new DocumentRepository();
        $source = $repository->get($userId, (int) $source['id']);
        $bytes = (new DocumentStorage())->read($source);
        if ($bytes === false) {
            throw new HttpException('The source CV could not be read.');
        }
        if ($source['mime_type'] === RendererClient::DOCX) {
            if ($format === 'pdf' || !empty($snapshot['cv_edits'])) {
                $bytes = $this->renderer->docx($bytes, $snapshot['cv_edits'] ?? [], $format);
            }
        } elseif ($source['mime_type'] !== 'application/pdf' || $format !== 'pdf') {
            throw new HttpException(
                'Upload an editable DOCX source for this conversion. A PDF CV can be retained unchanged.',
            );
        }
        $id = (new Exporter())->rendered(
            $userId,
            $bytes,
            $format,
            $name,
            'tailored_cv',
            $snapshot['document_text'] ?? ($source['extracted_text'] ?? ''),
            $parentId,
        );
        $repository->addCv(
            $userId,
            $id,
            array_replace($snapshot, [
                'layout_mode' => 'preserve',
                'source_cv_id' => (int) $source['id'],
            ]),
        );
        return $id;
    }
}
