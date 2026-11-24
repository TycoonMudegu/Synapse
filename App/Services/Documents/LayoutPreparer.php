<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Core\HttpException;
use App\Repositories\AccountRepository;
use App\Repositories\DocumentRepository;
use App\Services\AI\AiService;

final class LayoutPreparer
{
    public function __construct(
        private readonly RendererClient $renderer = new RendererClient(),
        private readonly AiService $ai = new AiService(),
    ) {}

    public function build(int $userId, array $profile, array $job, string $format): array
    {
        $repository = new DocumentRepository();
        $documents = $repository->all($userId);
        $preferences = (new AccountRepository())->preferences($userId);
        $cv = null;
        $template = null;
        foreach ($documents as $document) {
            if (!$cv && $document['is_master']) {
                $cv = $document;
            }
            if (!$template && $document['kind'] === 'cover_letter_template') {
                $template = $document;
            }
        }
        if (!$cv && $preferences['cv_document_id']) {
            $cv = $repository->get($userId, (int) $preferences['cv_document_id']);
        }
        if (!$cv) {
            throw new HttpException(
                'Upload a CV and choose it as your master CV before preparing an application.',
            );
        }
        if ($template && $template['mime_type'] !== RendererClient::DOCX) {
            throw new HttpException(
                'Upload the editable DOCX version of your cover letter template to keep its design while changing its text.',
            );
        }
        $source = $this->bytes($cv);
        $tailored = $profile;
        $changes = [];
        $cvEdits = [];
        if ($cv['mime_type'] === 'application/pdf') {
            if ($format !== 'pdf') {
                throw new HttpException(
                    'A PDF CV can be kept unchanged. Upload its editable DOCX version to change its text or export DOCX while preserving the design.',
                );
            }
            $cvBytes = $source;
            $cvText = $cv['extracted_text'] ?? '';
            $changes[] =
                'The uploaded PDF CV was copied unchanged to preserve its design. No text was tailored.';
        } elseif ($cv['mime_type'] === RendererClient::DOCX) {
            $inspection = $this->renderer->inspect($source);
            $edits = [];
            foreach ($inspection['paragraphs'] as $paragraph) {
                if (
                    $paragraph['part'] === 'word/document.xml' &&
                    $paragraph['editable'] &&
                    $this->normalise($paragraph['text']) === $this->normalise($profile['summary'])
                ) {
                    $suggestion = $this->ai->run($userId, 'tailoring', [
                        'profile' => $profile,
                        'job' => $job,
                        'maximum_summary_characters' => mb_strlen($paragraph['text']),
                        'preserve_layout' => true,
                    ]);
                    if (
                        mb_strlen($suggestion['summary']) >
                        max(100, (int) (mb_strlen($paragraph['text']) * 1.1))
                    ) {
                        throw new HttpException(
                            'The tailored summary is too long for the original design. Shorten it before preparing again.',
                        );
                    }
                    $edits[] = [
                        'part' => $paragraph['part'],
                        'index' => $paragraph['index'],
                        'original' => $paragraph['text'],
                        'text' => $suggestion['summary'],
                    ];
                    $tailored['summary'] = $suggestion['summary'];
                    break;
                }
            }
            $cvBytes = $this->renderer->docx($source, $edits, $format);
            $cvEdits = $edits;
            $cvText = $this->editedText($inspection['paragraphs'], $edits);
            $changes[] = $edits
                ? 'Only the source summary paragraph was tailored. Original sections, employment, skills, formatting, headers and assets were retained.'
                : 'The source summary could not be matched safely; the uploaded CV text and design were retained.';
        } else {
            throw new HttpException('Choose a PDF or DOCX master CV.');
        }
        if ($template) {
            $letterSource = $this->bytes($template);
            $inspection = $this->renderer->inspect($letterSource);
            $edits = [];
            for ($attempt = 0; $attempt < 2; $attempt++) {
                $paragraphs = array_map(function (array $paragraph) use ($attempt): array {
                    $length = mb_strlen($paragraph['text']);
                    $paragraph['maximum_text_characters'] =
                        $length >= 100
                            ? (int) floor($length * ($attempt === 0 ? 0.85 : 0.7))
                            : max(30, $length + 10);
                    return $paragraph;
                }, $inspection['paragraphs']);
                $result = $this->ai->run($userId, 'letter_edits', [
                    'profile' => $profile,
                    'job' => $job,
                    'application_date' => date('j F Y'),
                    'paragraphs' => $paragraphs,
                    'previous_edits' => $edits,
                ]);
                $edits = $result['edits'];
                $this->validateEdits($inspection['paragraphs'], $edits);
                $letterText = $this->editedText($inspection['paragraphs'], $edits);
                (new PreparationService())->validateLetter($letterText);
                if (
                    !str_contains($letterText, $profile['full_name']) ||
                    !str_contains($letterText, $profile['email'])
                ) {
                    throw new HttpException(
                        'The revised cover letter is missing your verified name or email. Review the editable template before preparing again.',
                    );
                }
                try {
                    $letterBytes = $this->renderer->docx($letterSource, $edits, $format);
                    break;
                } catch (HttpException $error) {
                    if ($attempt !== 0 || $error->status !== 409) {
                        throw $error;
                    }
                }
            }
        } else {
            $letterText = $this->ai->run($userId, 'letter', [
                'profile' => $profile,
                'job' => $job,
                'application_date' => date('j F Y'),
            ])['content'];
            (new PreparationService())->validateLetter($letterText);
            $letterBytes =
                $format === 'pdf' ? $this->renderer->html($this->letterHtml($letterText)) : null;
        }
        return [
            'cv_bytes' => $cvBytes,
            'cv_text' => $cvText,
            'letter_bytes' => $letterBytes,
            'letter_text' => $letterText,
            'profile' => $tailored,
            'changes' => $changes,
            'source_cv_id' => (int) $cv['id'],
            'source_letter_id' => $template ? (int) $template['id'] : null,
            'cv_edits' => $cvEdits,
        ];
    }

    public function validateEdits(array $paragraphs, array $edits): void
    {
        $allowed = [];
        foreach ($paragraphs as $paragraph) {
            $allowed[$paragraph['part'] . ':' . $paragraph['index']] = $paragraph;
        }
        $seen = [];
        foreach ($edits as $edit) {
            $key = $edit['part'] . ':' . $edit['index'];
            $original = $allowed[$key] ?? null;
            if (
                !$original ||
                !$original['editable'] ||
                $original['text'] !== $edit['original'] ||
                isset($seen[$key]) ||
                preg_match('/[\r\n\x00]/', $edit['text'])
            ) {
                throw new HttpException(
                    'The proposed edit does not match an editable source paragraph. The original document was kept.',
                );
            }
            $seen[$key] = true;
        }
    }

    private function bytes(array $document): string
    {
        $bytes = (new DocumentStorage())->read($document);
        if ($bytes === false || strlen($bytes) > 10 * 1024 * 1024) {
            throw new HttpException('Use a source document under 10 MiB.');
        }
        return $bytes;
    }

    private function editedText(array $paragraphs, array $edits): string
    {
        $replacements = [];
        foreach ($edits as $edit) {
            $replacements[$edit['part'] . ':' . $edit['index']] = $edit['text'];
        }
        return implode(
            "\n\n",
            array_map(
                fn($p) => $replacements[$p['part'] . ':' . $p['index']] ?? $p['text'],
                $paragraphs,
            ),
        );
    }

    private function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function letterHtml(string $text): string
    {
        $html =
            '<html><head><meta charset="utf-8"><style>@page{size:A4;margin:18mm 20mm}body{font-family:"Liberation Serif",serif;font-size:11pt;line-height:1.35;color:#17212b}p{margin:0 0 9pt;orphans:3;widows:3}</style></head><body>';
        foreach (preg_split('/\R\s*\R/u', $text) as $paragraph) {
            $html .= '<p>' . nl2br(e($paragraph)) . '</p>';
        }
        return $html . '</body></html>';
    }
}
