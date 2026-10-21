<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications\Channels;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Tbtop\Cms\Notifications\Rendered;

/** Wraps an already-rendered Html-format notification for CmsMailer::send(). */
final class RenderedMail extends Mailable
{
    public function __construct(private readonly Rendered $rendered) {}

    public function envelope(): Envelope
    {
        // TemplateRenderer already decoded the subject to plain text.
        return new Envelope(subject: $this->rendered->subject);
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->rendered->body);
    }
}
