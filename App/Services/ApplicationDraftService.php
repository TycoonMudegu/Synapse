<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Repositories\ApplicationRepository;
use App\Repositories\DocumentRepository;
use App\Services\Documents\Exporter;
use App\Services\Security\Validator;

final class ApplicationDraftService
{
    public function save(int $userId, int $id, array $input): void
    {
        \App\Core\Database::transaction(function () use ($input, $userId, $id): void {
            $repository = new ApplicationRepository();
            $repository->lockUser($userId);
            $application = $repository->get($userId, $id, true);
            if (!in_array($application['status'], ['draft', 'ready_for_review', 'failed'], true)) {
                throw new HttpException(
                    'Return this application to review before editing its documents.',
                );
            }
            $data = $input;
            $changes = [
                'subject' => Validator::text($input['subject'] ?? '', 'email subject', 250, true),
                'email_body' => Validator::text($input['email_body'] ?? '', 'email body', 20000),
                'notes' => Validator::text($input['notes'] ?? '', 'notes', 20000),
                'approved_at' => null,
            ];
            foreach (['cv_document_id', 'letter_document_id'] as $field) {
                $value = (int) ($data[$field] ?? 0);
                if ($value > 0) {
                    $document = (new DocumentRepository())->get($userId, $value);
                    \App\Services\Documents\DocumentStorage::assertAvailable($document);
                    if (
                        !in_array(
                            $document['mime_type'],
                            [
                                'application/pdf',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            ],
                            true,
                        )
                    ) {
                        throw new HttpException(
                            'Application attachments must be PDF or DOCX documents.',
                        );
                    }
                }
                $changes[$field] = $value ?: null;
            }
            $changes['documents_verified_at'] = !empty($data['verified'])
                ? date('Y-m-d H:i:s')
                : null;
            $changes['status'] =
                $changes['cv_document_id'] && $changes['letter_document_id']
                    ? 'ready_for_review'
                    : 'draft';
            $repository->update($userId, $id, $changes);
        });
    }

    public function interview(int $userId, int $id, array $input): void
    {
        $repository = new ApplicationRepository();
        $application = $repository->get($userId, $id);
        if (
            !in_array(
                $application['status'],
                ['submitted', 'awaiting_external_action', 'interview'],
                true,
            )
        ) {
            throw new HttpException('Only submitted applications can have an interview scheduled.');
        }
        $value = Validator::text($input['interview_at'] ?? '', 'interview date and time', 20, true);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i', $value);
        if (!$date || $date->format('Y-m-d\\TH:i') !== $value) {
            throw new HttpException('Enter a valid interview date and time in Africa/Nairobi.');
        }
        if ($application['status'] !== 'interview') {
            (new ApplicationService())->transition($userId, $id, 'interview');
        }
        $repository->update($userId, $id, ['interview_at' => $date->format('Y-m-d H:i:s')]);
        (new \App\Repositories\ActivityRepository())->notify(
            $userId,
            'interview',
            'Interview scheduled',
            $application['title'] . ' · ' . $date->format('d M Y, H:i') . ' EAT',
            '/applications/' . $id,
        );
    }

    public function letter(int $userId, int $id, array $input): void
    {
        \App\Core\Database::transaction(function () use ($input, $userId, $id): void {
            $repository = new ApplicationRepository();
            $repository->lockUser($userId);
            $application = $repository->get($userId, $id, true);
            if (!in_array($application['status'], ['draft', 'ready_for_review'], true)) {
                throw new HttpException(
                    'Return this application to review before editing the cover letter.',
                );
            }
            $documentSettings = (new \App\Repositories\SettingsRepository())->get(
                $userId,
                'documents',
            );
            if ($documentSettings['layout_mode'] === 'preserve') {
                foreach ((new DocumentRepository())->all($userId) as $document) {
                    if ($document['kind'] === 'cover_letter_template') {
                        throw new HttpException(
                            'To keep your cover letter design, regenerate from your DOCX template or upload an edited document and attach it. Plain-text editing is available with the simple app layout.',
                        );
                    }
                }
            }
            $content = Validator::text($input['content'] ?? '', 'cover letter', 20000, true);
            $format = Validator::choice($input['format'] ?? 'pdf', 'document format', [
                'pdf',
                'docx',
            ]);
            $documentId = (new Exporter())->letter(
                $userId,
                $content,
                $format,
                'Cover letter - ' . $application['employer'],
                $application['letter_document_id']
                    ? (int) $application['letter_document_id']
                    : null,
            );
            $repository->saveLetter(
                $userId,
                $id,
                $content,
                $application['cv_document_id'] ? (int) $application['cv_document_id'] : null,
            );
            $repository->update($userId, $id, [
                'letter_document_id' => $documentId,
                'documents_verified_at' => null,
                'approved_at' => null,
            ]);
        });
    }
}
