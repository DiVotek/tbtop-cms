<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Block\SubmitContext;
use Tbtop\Cms\Mail\ContactFormMail;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
});

it('exposes code-authored fields and copy to the frontend and ignores a stored legacy recipient', function (): void {
    $props = (new ContactForm)->resolve([
        'heading' => 'Talk to us',
        'submitLabel' => 'Go',
        'successMessage' => 'Done',
        'recipient' => 'ops@example.test',
    ], new ResolveContext('en', 'en'));

    expect($props['heading'])->toBe('Talk to us')
        ->and($props['submitLabel'])->toBe('Go')
        ->and($props['successMessage'])->toBe('Done')
        ->and($props)->not->toHaveKey('recipient')
        ->and($props['fields'])->toHaveCount(3);
});

it('requires name, email and message on submit', function (): void {
    $rules = (new ContactForm)->submitRules([], new SubmitContext('en', 'en'));

    expect($rules)->toHaveKeys(['name', 'email', 'message'])
        ->and($rules['email'])->toContain('email');
});

it('does not send mail when notifications are disabled', function (): void {
    Mail::fake();
    Log::spy();
    app(Settings::class)->set('notifications.mail.enabled', false);
    app(Settings::class)->set('notifications.mail.recipients', 'ops@example.test');

    $payload = ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'];

    (new ContactForm)->handleSubmit($payload, [], new SubmitContext('en', 'en'));

    Mail::assertNothingSent();
    Log::shouldHaveReceived('debug')->with('form.contact.notifications_disabled', Mockery::type('array'))->once();
});

it('does not send mail when enabled but no recipients are configured', function (): void {
    Mail::fake();
    Log::spy();
    app(Settings::class)->set('notifications.mail.enabled', true);

    $payload = ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'];

    (new ContactForm)->handleSubmit($payload, [], new SubmitContext('en', 'en'));

    Mail::assertNothingSent();
    Log::shouldHaveReceived('debug')->with('form.contact.no_recipients', Mockery::type('array'))->once();
});

it('sends mail to every configured recipient when enabled, ignoring the legacy per-block recipient', function (): void {
    Mail::fake();
    app(Settings::class)->set('notifications.mail.enabled', true);
    app(Settings::class)->set('notifications.mail.recipients', "ops@example.test\nsales@example.test");

    $payload = ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'];

    (new ContactForm)->handleSubmit($payload, ['recipient' => 'legacy@example.test'], new SubmitContext('en', 'en'));

    Mail::assertSent(ContactFormMail::class, fn (ContactFormMail $mail): bool => $mail->hasTo('ops@example.test') && $mail->hasTo('sales@example.test') && ! $mail->hasTo('legacy@example.test'));
});
