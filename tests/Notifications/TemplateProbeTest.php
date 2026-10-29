<?php

declare(strict_types=1);

use Tbtop\Cms\Form\Notifications\FormSubmissionPayload;
use Tbtop\Cms\Notifications\Support\InvalidTemplate;
use Tbtop\Cms\Notifications\Support\TemplateProbe;
use Tbtop\Cms\Tests\Fixtures\TestNotificationPayload;

it('passes for a template that renders cleanly against a stubbed payload', function (): void {
    app(TemplateProbe::class)->check(TestNotificationPayload::class, 'Hello {{ $message }}', 'en');
})->throwsNoExceptions();

it('throws InvalidTemplate for a template referencing an undefined variable', function (): void {
    app(TemplateProbe::class)->check(TestNotificationPayload::class, 'Hello {{ $nope }}', 'en');
})->throws(InvalidTemplate::class);

it('throws InvalidTemplate for a template with an unclosed @if directive', function (): void {
    app(TemplateProbe::class)->check(TestNotificationPayload::class, 'Hello @if($message)there', 'en');
})->throws(InvalidTemplate::class);

it('passes for a template that reads a submitted field by its key', function (): void {
    // The stub's `fields` is empty, so any key is missing from it; a real submission carries the form's keys.
    app(TemplateProbe::class)->check(FormSubmissionPayload::class, 'Phone: {{ $fields[\'phone\'] }} ({{ $fieldLabels[\'phone\'] }})', 'en');
})->throwsNoExceptions();

it('still throws InvalidTemplate when a template indexes something that is not an array', function (): void {
    app(TemplateProbe::class)->check(FormSubmissionPayload::class, 'Phone: {{ $pageName->phone() }}', 'en');
})->throws(InvalidTemplate::class);

it('still checks the rest of a template after a submitted field', function (): void {
    app(TemplateProbe::class)->check(FormSubmissionPayload::class, '{{ $fields[\'phone\'] }} {{ $nope }}', 'en');
})->throws(InvalidTemplate::class);
