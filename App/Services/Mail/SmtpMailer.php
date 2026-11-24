<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Core\Env;
use App\Core\HttpException;
use App\Services\Documents\DocumentService;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

final class SmtpMailer
{
    public function __construct(private readonly ?PHPMailer $mailer = null) {}

    public function configured(): bool
    {
        $host = Env::get('SMTP_HOST', 'smtp.gmail.com');
        $port = Env::get('SMTP_PORT', '587');
        $username = Env::get('SMTP_USERNAME');
        return (bool) preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9.-]*[a-zA-Z0-9])?$/D', $host) &&
            ctype_digit($port) &&
            (int) $port > 0 &&
            (int) $port <= 65535 &&
            in_array(Env::get('SMTP_ENCRYPTION', 'tls'), ['tls', 'ssl'], true) &&
            trim($username) !== '' &&
            (strtolower($host) !== 'smtp.gmail.com' ||
                filter_var($username, FILTER_VALIDATE_EMAIL) !== false) &&
            Env::get('SMTP_PASSWORD') !== '' &&
            filter_var($this->from(), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function send(
        string $email,
        string $name,
        string $subject,
        string $text,
        string $html,
        string $messageId,
        array $documents = [],
    ): void {
        if (!$this->configured()) {
            throw new HttpException('The site owner needs to configure SMTP job alerts.');
        }
        try {
            $mail = $this->mailer ?? new PHPMailer(true);
            $mail->clearAllRecipients();
            $mail->clearAttachments();
            $mail->isSMTP();
            $mail->SMTPDebug = SMTP::DEBUG_OFF;
            $mail->Host = Env::get('SMTP_HOST', 'smtp.gmail.com');
            $mail->Port = (int) Env::get('SMTP_PORT', '587');
            $mail->SMTPAuth = true;
            $mail->Username = Env::get('SMTP_USERNAME');
            $mail->Password = Env::get('SMTP_PASSWORD');
            $mail->SMTPSecure =
                Env::get('SMTP_ENCRYPTION', 'tls') === 'ssl'
                    ? PHPMailer::ENCRYPTION_SMTPS
                    : PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ];
            $mail->Timeout = 15;
            $mail->getSMTPInstance()->Timelimit = 30;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom(
                $this->from(),
                Env::get('SMTP_FROM_NAME') ?: Env::get('APP_NAME', 'Synapse'),
            );
            $mail->addAddress($email, $name);
            $mail->Subject = str_replace(["\r", "\n"], ' ', $subject);
            $mail->MessageID = $messageId;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;
            foreach ($documents as $document) {
                $mail->addStringAttachment(
                    (new \App\Services\Documents\DocumentStorage())->read($document),
                    str_replace('_', ' ', basename($document['name'])),
                    PHPMailer::ENCODING_BASE64,
                    $document['mime_type'],
                );
            }
            if (!$mail->send()) {
                throw new \RuntimeException('SMTP did not confirm delivery.');
            }
        } catch (\Throwable) {
            // Provider errors can contain credentials, addresses or complete SMTP conversations.
            throw new HttpException(
                'Email delivery was not confirmed. Check SMTP settings and the sender mailbox before retrying.',
                502,
            );
        }
    }

    private function from(): string
    {
        return Env::get('SMTP_FROM_EMAIL') ?: Env::get('SMTP_USERNAME');
    }
}
