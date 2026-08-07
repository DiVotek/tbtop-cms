<?php

declare(strict_types=1);

namespace Tbtop\Cms\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Tbtop\Cms\Settings\Settings;

class TestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Test email from ' . $this->siteName());
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>This is a test email from ' . e($this->siteName()) . ' mail settings.</p>',
        );
    }

    private function siteName(): string
    {
        $name = app(Settings::class)->get('site.name');

        return is_string($name) && trim($name) !== '' ? $name : (string) config('app.name');
    }
}
