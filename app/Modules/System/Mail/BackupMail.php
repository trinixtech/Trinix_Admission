<?php

namespace App\Modules\System\Mail;

use App\Modules\System\Services\BackupService;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The daily backup: the encrypted ZIP attached, or a notice when it is too large for email. Never the password. */
class BackupMail extends Mailable
{
    public function __construct(public array $info, public ?string $attachPath, public string $siteUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Trinix Admission backup '.substr($this->info['name'], -19, 15));
    }

    public function content(): Content
    {
        $size = BackupService::humanSize($this->info['size']);
        $text = $this->attachPath
            ? "The database backup of {$this->siteUrl} is attached ({$this->info['name']}, $size).\n\n"
              .(str_ends_with($this->info['name'], '.zip') ? 'It is an encrypted ZIP: open it with the backup password set in System → Backups.' : 'It is encrypted (AES-256) with the backup password set in System → Backups.')." The password is never sent by email.\n"
              ."Keep this email in a safe place; it holds applicants' personal data (DPDP Act 2023).\n\n"
              .'To restore: System → Backups → Upload a backup, then Restore.'
            : "The database backup of {$this->siteUrl} was made ({$this->info['name']}, $size), but it is larger than the email limit, so it is not attached.\n\n"
              .'Sign in and download it from System → Backups.';

        return new Content(text: 'mail.plain', with: ['text' => $text]);
    }

    public function attachments(): array
    {
        return $this->attachPath ? [Attachment::fromPath($this->attachPath)->as($this->info['name'])->withMime(str_ends_with($this->info['name'], '.zip') ? 'application/zip' : 'application/octet-stream')] : [];
    }
}
