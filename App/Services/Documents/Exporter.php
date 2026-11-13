<?php

declare(strict_types=1);

namespace App\Services\Documents;

use App\Core\HttpException;
use App\Repositories\DocumentRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use ZipArchive;

final class Exporter
{
    public function cv(
        int $userId,
        array $profile,
        string $format,
        string $name = 'Career CV',
        ?int $parentId = null,
    ): int {
        $sections = [
            [
                'title' => $profile['full_name'],
                'text' => implode(
                    ' · ',
                    array_filter([$profile['email'], $profile['phone'], $profile['location']]),
                ),
            ],
            ['title' => 'Professional summary', 'text' => (string) ($profile['summary'] ?? '')],
            ['title' => 'Skills', 'text' => implode(' · ', $profile['skills'])],
        ];
        foreach ($profile['employment'] as $role) {
            $sections[] = [
                'title' => $role['position'] . ' | ' . $role['employer'],
                'text' =>
                    $role['start_date'] . ' – ' . $role['end_date'] . "\n" . $role['achievements'],
            ];
        }
        foreach ($profile['education'] as $qualification) {
            $sections[] = [
                'title' => $qualification['qualification'],
                'text' => $qualification['institution'] . ' · ' . $qualification['completion_year'],
            ];
        }
        foreach ($profile['details'] ?? [] as $key => $value) {
            if (trim((string) $value) !== '') {
                $sections[] = ['title' => ucfirst(str_replace('_', ' ', $key)), 'text' => $value];
            }
        }
        return $this->save($userId, $sections, $format, $name, 'tailored_cv', $parentId);
    }

    public function letter(
        int $userId,
        string $content,
        string $format,
        string $name,
        ?int $parentId = null,
    ): int {
        return $this->save(
            $userId,
            [['title' => '', 'text' => $content]],
            $format,
            $name,
            'cover_letter',
            $parentId,
        );
    }

    public function save(
        int $userId,
        array $sections,
        string $format,
        string $name,
        string $kind,
        ?int $parentId = null,
    ): int {
        if (!in_array($format, ['pdf', 'docx'], true)) {
            throw new HttpException('Choose PDF or DOCX.');
        }
        $path = bin2hex(random_bytes(24)) . '.' . $format;
        $absolute = BASE_PATH . '/storage/private/' . $path;
        $text = implode(
            "\n\n",
            array_map(
                fn(array $section) => trim($section['title'] . "\n" . $section['text']),
                $sections,
            ),
        );
        if ($format === 'pdf') {
            $options = new Options();
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('tempDir', BASE_PATH . '/storage/private');
            $pdf = new Dompdf($options);
            $html =
                '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans;font-size:10pt;line-height:1.6;color:#233847}h2{font-size:12pt;color:#326080;margin:18px 0 5px}p{margin:0 0 10px;white-space:pre-line}@page{margin:45px}</style></head><body>';
            foreach ($sections as $section) {
                $html .=
                    ($section['title'] !== '' ? '<h2>' . e($section['title']) . '</h2>' : '') .
                    '<p>' .
                    nl2br(e($section['text'])) .
                    '</p>';
            }
            $pdf->loadHtml($html . '</body></html>', 'UTF-8');
            $pdf->setPaper('A4');
            $pdf->render();
            file_put_contents($absolute, $pdf->output(), LOCK_EX);
        } else {
            $this->docx($sections, $absolute);
        }
        chmod($absolute, 0600);
        $repository = new DocumentRepository();
        $version = 1;
        if ($parentId !== null) {
            $parent = $repository->get($userId, $parentId);
            $version = (int) $parent['version'] + 1;
        }
        $id = $repository->create($userId, [
            'name' => $name . '.' . $format,
            'kind' => $kind,
            'storage_path' => $path,
            'mime_type' =>
                $format === 'pdf'
                    ? 'application/pdf'
                    : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'byte_size' => filesize($absolute),
            'extracted_text' => $text,
            'parent_id' => $parentId,
            'version' => $version,
        ]);
        return $id;
    }

    private function docx(array $sections, string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new HttpException('The DOCX file could not be created.', 500);
        }
        $zip->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>',
        );
        $zip->addFromString(
            '_rels/.rels',
            '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
        );
        $body = '';
        foreach ($sections as $section) {
            foreach (
                [$section['title'], ...preg_split('/\R/', (string) $section['text'])]
                as $index => $line
            ) {
                $safe = htmlspecialchars($line, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $body .=
                    '<w:p><w:pPr><w:spacing w:after="140"/></w:pPr><w:r><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/>' .
                    ($index === 0
                        ? '<w:b/><w:sz w:val="26"/><w:color w:val="326080"/>'
                        : '<w:sz w:val="22"/>') .
                    '</w:rPr><w:t xml:space="preserve">' .
                    $safe .
                    '</w:t></w:r></w:p>';
            }
        }
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' .
                $body .
                '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="900" w:right="900" w:bottom="900" w:left="900"/></w:sectPr></w:body></w:document>',
        );
        $zip->close();
    }
}
