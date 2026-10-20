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
        return new Envelope(subject: __('tbtop-cms::notifications.mail.test_subject', ['site' => $this->siteName()]));
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>' . e(__('tbtop-cms::notifications.mail.test_body', ['site' => $this->siteName()])) . '</p>',
        );
    }

    private function siteName(): string
    {
        $name = app(Settings::class)->get('site.name');

        return is_string($name) && trim($name) !== '' ? $name : (string) config('app.name');
    }
}
