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
        // Blade escapes the subject section like any echo; a mail header is plain text.
        return new Envelope(subject: html_entity_decode($this->rendered->subject, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->rendered->body);
    }
}
