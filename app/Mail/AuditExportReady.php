<?php

namespace App\Mail;

use App\Jobs\GenerateAuditExport;
use App\Models\AuditExport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AuditExportReady extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public AuditExport $export) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Your audit export is ready'),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.audit-export-ready',
            with: [
                'teamName' => $this->export->team->name,
                'logLabel' => $this->export->log_type->label(),
                'formatLabel' => $this->export->format->label(),
                'url' => route('teams.audit-exports.download', [$this->export->team, $this->export]),
                'expiresAt' => $this->export->expires_at,
                'retentionDays' => GenerateAuditExport::RETENTION_DAYS,
            ],
        );
    }
}
