<?php

declare(strict_types=1);

namespace App\Services\Applications;

interface SubmissionAdapter
{
    public function method(): string;

    /** Return a confirmed remote submission ID; throw on ambiguous delivery. */
    public function submit(int $userId, array $application, array $attachments): array;
}
