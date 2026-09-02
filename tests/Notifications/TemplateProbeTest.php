<?php

declare(strict_types=1);

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
