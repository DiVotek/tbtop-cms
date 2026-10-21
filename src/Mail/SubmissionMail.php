<?php

declare(strict_types=1);

namespace Tbtop\Cms\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;
use Tbtop\Cms\Form\Models\FormSubmission;

/**
 * A ready-made Mailable for any HandlesFormSubmission block's payload, kept
 * for hosts that send it themselves. The package's own pipeline does not use
 * it: SubmissionNotifier's mail channel (MailChannel) sends a RenderedMail
 * built from the form.submission template,
 * tbtop-cms::notifications.form-submission.html — to change that email,
 * override resources/views/vendor/tbtop-cms/notifications/form-submission/html.blade.php
 * (see docs/host/ai/notifications.md). Subject and body are translated in the
 * app's current locale via tbtop-cms::notifications.
 */
class SubmissionMail extends Mailable
{
    public function __construct(private readonly FormSubmission $submission, private readonly string $pageName) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('tbtop-cms::notifications.mail.subject'));
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->body());
    }

    private function body(): string
    {
        $rows = [];
        foreach ($this->submission->payload as $key => $value) {
            $rows[] = '<p><strong>' . e((string) $key) . ':</strong> ' . nl2br(e($this->formatValue($value))) . '</p>';
        }

        $meta = '<p><strong>' . e(__('tbtop-cms::notifications.mail.fields.page')) . ':</strong> ' . e($this->pageName) . '</p>'
            . '<p><strong>' . e(__('tbtop-cms::notifications.mail.fields.locale')) . ':</strong> ' . e($this->submission->locale) . '</p>'
            . '<p><strong>' . e(__('tbtop-cms::notifications.mail.fields.submitted_at')) . ':</strong> ' . e($this->submittedAt()) . '</p>';

        $link = '<p><a href="' . e($this->detailUrl()) . '">' . e(__('tbtop-cms::notifications.mail.view_submission')) . '</a></p>';

        return implode("\n", $rows) . $meta . $link;
    }

    private function formatValue(mixed $value): string
    {
        if (! is_array($value) && ! is_object($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $encoded === false ? '[unsupported value]' : $encoded;
    }

    private function submittedAt(): string
    {
        $createdAt = $this->submission->created_at ?? Carbon::now();

        return $createdAt->format(__('tbtop-cms::form-submissions.date_format'));
    }

    private function detailUrl(): string
    {
        return route('tbtop.cms.form-submission-page', ['submission' => $this->submission->id]);
    }
}
