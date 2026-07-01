<?php

declare(strict_types=1);

namespace Tbtop\Cms\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ContactFormMail extends Mailable
{
    /** @param array<string, mixed> $payload */
    public function __construct(public array $payload) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'New contact form submission');
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->body());
    }

    private function body(): string
    {
        $rows = [];

        foreach ($this->payload as $key => $value) {
            $rows[] = '<p><strong>' . e((string) $key) . ':</strong> ' . nl2br(e((string) $value)) . '</p>';
        }

        return implode("\n", $rows);
    }
}
