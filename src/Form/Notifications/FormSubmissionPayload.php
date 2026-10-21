<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form\Notifications;

/**
 * Payload for the `form.submission` notification type. Plain values only —
 * a template never queries (ADR-0015 spirit) — captured once by
 * SubmissionNotifier from FormSubmission + Page before Notifications::send()
 * renders it per recipient/channel.
 */
final readonly class FormSubmissionPayload
{
    /** @param array<string, mixed> $fields */
    public function __construct(
        public string $leadIdentity,
        public string $pageName,
        public string $locale,
        public string $submittedAt,
        public array $fields,
        public string $actionUrl,
        public string $actionLabel = '',
    ) {}
}
