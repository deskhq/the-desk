<?php

namespace App\Mail;

use App\Models\DataExport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DataExportReady extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public DataExport $export) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your data export is ready',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.data-export-ready',
            with: [
                'url' => route('data-export.download', $this->export),
                'expiresAt' => $this->export->expires_at,
            ],
        );
    }
}
