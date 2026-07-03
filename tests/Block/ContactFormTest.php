<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Block\SubmitContext;
use Tbtop\Cms\Mail\ContactFormMail;

it('exposes code-authored fields and copy to the frontend without leaking the recipient', function (): void {
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

it('requires the recipient field to be a valid email', function (): void {
    $field = collect((new ContactForm)->form(new S))->firstWhere('name', 'recipient');

    expect($field)->not->toBeNull()
        ->and($field->ruleEntries())->toContain('email');
});

it('does not send mail and logs a warning when the configured recipient is not a valid email', function (): void {
    Mail::fake();
    Log::spy();

    $payload = ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'];

    (new ContactForm)->handleSubmit($payload, ['recipient' => 'not-an-email'], new SubmitContext('en', 'en'));

    Mail::assertNothingSent();
    Log::shouldHaveReceived('warning')->with('form.contact.invalid_recipient', Mockery::type('array'))->once();
});

it('does not send mail and logs a warning when the recipient contains header injection characters', function (): void {
    Mail::fake();
    Log::spy();

    $payload = ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'];

    (new ContactForm)->handleSubmit($payload, ['recipient' => "victim@example.test\r\nBcc: attacker@example.test"], new SubmitContext('en', 'en'));

    Mail::assertNothingSent();
    Log::shouldHaveReceived('warning')->with('form.contact.invalid_recipient', Mockery::type('array'))->once();
});

it('sends mail to a validly configured recipient', function (): void {
    Mail::fake();

    $payload = ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'];

    (new ContactForm)->handleSubmit($payload, ['recipient' => 'ops@example.test'], new SubmitContext('en', 'en'));

    Mail::assertSent(ContactFormMail::class, fn (ContactFormMail $mail): bool => $mail->hasTo('ops@example.test'));
});
