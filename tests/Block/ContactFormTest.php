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
