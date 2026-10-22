<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use Tbtop\Cms\Block\Blocks\ContactForm;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Block\SubmitContext;

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

it('does nothing on submit — notifying the team is SubmissionNotifier\'s job, not the block\'s', function (): void {
    Mail::fake();

    $payload = ['name' => 'Ada', 'email' => 'ada@example.test', 'message' => 'Hi'];

    (new ContactForm)->handleSubmit($payload, [], new SubmitContext('en', 'en'));

    Mail::assertNothingSent();
});

it('defaults blank copy and the field labels in the page locale', function (): void {
    $props = (new ContactForm)->resolve([
        'heading' => '',
        'submitLabel' => '  ',
    ], new ResolveContext('uk', 'en'));

    expect($props['heading'])->toBe(__('tbtop-cms::forms.contact.heading', [], 'uk'))
        ->and($props['heading'])->not->toBe('Contact us')
        ->and($props['submitLabel'])->toBe(__('tbtop-cms::forms.contact.submit', [], 'uk'))
        ->and($props['successMessage'])->toBe(__('tbtop-cms::forms.contact.success', [], 'uk'))
        ->and(array_column($props['fields'], 'label'))->toBe([
            __('tbtop-cms::forms.contact.fields.name', [], 'uk'),
            __('tbtop-cms::forms.contact.fields.email', [], 'uk'),
            __('tbtop-cms::forms.contact.fields.message', [], 'uk'),
        ]);
});

it('keeps the English defaults for an English page', function (): void {
    $props = (new ContactForm)->resolve([], new ResolveContext('en', 'en'));

    expect($props['heading'])->toBe('Contact us')
        ->and($props['submitLabel'])->toBe('Send')
        ->and($props['successMessage'])->toBe('Thanks, we will be in touch.')
        ->and(array_column($props['fields'], 'label'))->toBe(['Name', 'Email', 'Message']);
});
