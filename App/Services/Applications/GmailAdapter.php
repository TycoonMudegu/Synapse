<?php

declare(strict_types=1);

namespace App\Services\Applications;

use App\Services\Mail\GmailService;

final class GmailAdapter implements SubmissionAdapter
{
    public function method(): string
    {
        return 'email';
    }

    public function submit(int $userId, array $application, array $attachments): array
    {
        return (new GmailService())->send(
            $userId,
            $application['application_email'],
            $application['subject'],
            $application['email_body'],
            $attachments,
            $application['send_key'],
        );
    }
}
