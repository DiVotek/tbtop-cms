<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tbtop\Cms\Notifications\Audience;
use Tbtop\Cms\Notifications\Delivery;
use Tbtop\Cms\Notifications\Events\NotificationFailed;
use Tbtop\Cms\Notifications\Events\NotificationSent;
use Tbtop\Cms\Notifications\Format;
use Tbtop\Cms\Notifications\Models\NotificationLog;
use Tbtop\Cms\Notifications\Models\NotificationTemplate;
use Tbtop\Cms\Notifications\NotificationLogStatus;
use Tbtop\Cms\Notifications\Notifications;
use Tbtop\Cms\Notifications\Recipient;
use Tbtop\Cms\Notifications\TypeDefinition;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Tests\Fixtures\RecordingChannel;
use Tbtop\Cms\Tests\Fixtures\RecordingShortChannel;
use Tbtop\Cms\Tests\Fixtures\TestNotificationPayload;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    RecordingChannel::reset();
    RecordingShortChannel::reset();

    File::put(resource_path('views/test-notification-text.blade.php'), '@section(\'subject\', \'Hello \' . $message)Body: {{ $message }}');
    File::put(resource_path('views/test-notification-short.blade.php'), 'Short: {{ $message }}');

    Notifications::channel(RecordingChannel::class);
    Notifications::channel(RecordingShortChannel::class);

    Notifications::type(
        TypeDefinition::make('test.recorded')
            ->label('Test recorded')
            ->group('test')
            ->payload(TestNotificationPayload::class)
            ->templates([
                Format::Text->value => 'test-notification-text',
                Format::Short->value => 'test-notification-short',
            ])
            ->audiences([Audience::Customer])
            ->defaultChannels(['recording', 'recording-short']),
    );
});

afterEach(function (): void {
    File::delete(resource_path('views/test-notification-text.blade.php'));
    File::delete(resource_path('views/test-notification-short.blade.php'));
});

it('renders the type template for each registered channel and delivers to a supporting recipient', function (): void {
    $recipient = new Recipient(Audience::Customer, 'en', email: 'ana@example.test');

    Notifications::send('test.recorded', new TestNotificationPayload('hi'), [$recipient]);

    $textDeliveries = RecordingChannel::deliveries();
    $shortDeliveries = RecordingShortChannel::deliveries();

    expect($textDeliveries)->toHaveCount(1)
        ->and($textDeliveries[0]['message']->subject)->toBe('Hello hi')
        ->and($textDeliveries[0]['message']->body)->toContain('Body: hi')
        ->and($textDeliveries[0]['recipient']->email)->toBe('ana@example.test')
        ->and($shortDeliveries)->toHaveCount(1)
        ->and($shortDeliveries[0]['message']->body)->toContain('Short: hi');
});

it('skips a channel a recipient does not support', function (): void {
    $noEmail = new Recipient(Audience::Customer, 'en', notifiable: null);

    Notifications::send('test.recorded', new TestNotificationPayload('hi'), [$noEmail]);

    expect(RecordingChannel::deliveries())->toBeEmpty()
        ->and(RecordingShortChannel::deliveries())->toBeEmpty();
});

it('skips a recipient whose audience the type does not accept', function (): void {
    $staff = new Recipient(Audience::Staff, 'en', email: 'staff@example.test');

    Notifications::send('test.recorded', new TestNotificationPayload('hi'), [$staff]);

    expect(RecordingChannel::deliveries())->toBeEmpty();
});

it('honors a per-type channel toggle in settings over the registered defaults', function (): void {
    app(Settings::class)->set('notifications.test.recorded.channels', ['recording-short']);
    $recipient = new Recipient(Audience::Customer, 'en', email: 'ana@example.test');

    Notifications::send('test.recorded', new TestNotificationPayload('hi'), [$recipient]);

    expect(RecordingChannel::deliveries())->toBeEmpty()
        ->and(RecordingShortChannel::deliveries())->toHaveCount(1);
});

it('writes a sent log row and dispatches NotificationSent for a successful delivery', function (): void {
    Event::fake([NotificationSent::class]);
    $recipient = new Recipient(Audience::Customer, 'en', email: 'ana@example.test');

    Notifications::send('test.recorded', new TestNotificationPayload('hi'), [$recipient]);

    $log = NotificationLog::query()->where('channel', 'recording')->sole();
    expect($log->type)->toBe('test.recorded')
        ->and($log->status)->toBe(NotificationLogStatus::Sent)
        ->and($log->recipient)->toBe('ana@example.test')
        ->and($log->sent_at)->not->toBeNull();

    Event::assertDispatched(NotificationSent::class, fn (NotificationSent $event): bool => $event->channel === 'recording' && $event->type === 'test.recorded');
});

it('logs one channel as failed and dispatches NotificationFailed while other channels still deliver', function (): void {
    Event::fake([NotificationFailed::class]);
    RecordingChannel::$throwForEmail = 'ana@example.test';
    $recipient = new Recipient(Audience::Customer, 'en', email: 'ana@example.test');

    Notifications::send('test.recorded', new TestNotificationPayload('hi'), [$recipient]);

    $failedLog = NotificationLog::query()->where('channel', 'recording')->sole();
    expect($failedLog->status)->toBe(NotificationLogStatus::Failed)
        ->and($failedLog->error)->toContain('recording channel refused ana@example.test');

    // The other registered channel for this recipient still delivered.
    expect(RecordingShortChannel::deliveries())->toHaveCount(1);

    Event::assertDispatched(
        NotificationFailed::class,
        fn (NotificationFailed $event): bool => $event->channel === 'recording' && str_contains($event->error, 'refused'),
    );
});

it('returns one delivery per attempted recipient and channel, carrying each outcome', function (): void {
    RecordingChannel::$throwForEmail = 'ana@example.test';
    $recipient = new Recipient(Audience::Customer, 'en', email: 'ana@example.test');

    $deliveries = Notifications::send('test.recorded', new TestNotificationPayload('hi'), [$recipient]);

    expect($deliveries)->toHaveCount(2);

    $byChannel = collect($deliveries)->keyBy(fn (Delivery $delivery): string => $delivery->channel);

    expect($byChannel['recording']->status)->toBe(NotificationLogStatus::Failed)
        ->and($byChannel['recording']->failed())->toBeTrue()
        ->and($byChannel['recording']->error)->toContain('refused ana@example.test')
        ->and($byChannel['recording']->recipient->email)->toBe('ana@example.test')
        ->and($byChannel['recording-short']->status)->toBe(NotificationLogStatus::Sent)
        ->and($byChannel['recording-short']->failed())->toBeFalse()
        ->and($byChannel['recording-short']->error)->toBeNull();
});

it('reports only its own deliveries when an unrelated failed row shares the log', function (): void {
    NotificationLog::query()->create([
        'type' => 'test.recorded',
        'channel' => 'recording',
        'audience' => Audience::Customer,
        'recipient' => 'someone-else@example.test',
        'locale' => 'en',
        'status' => NotificationLogStatus::Failed,
        'error' => 'a concurrent caller failed',
    ]);

    $recipient = new Recipient(Audience::Customer, 'en', email: 'ana@example.test');

    $deliveries = Notifications::send('test.recorded', new TestNotificationPayload('hi'), [$recipient]);

    expect(array_filter($deliveries, fn (Delivery $delivery): bool => $delivery->failed()))->toBeEmpty()
        ->and($deliveries)->toHaveCount(2);
});

it('renders a stored template override instead of the built-in view', function (): void {
    NotificationTemplate::query()->create([
        'type' => 'test.recorded',
        'format' => Format::Text,
        'locale' => 'en',
        'subject' => 'Override for {{ $message }}',
        'body' => 'Overridden body: {{ $message }}',
    ]);

    $recipient = new Recipient(Audience::Customer, 'en', email: 'ana@example.test');

    Notifications::send('test.recorded', new TestNotificationPayload('hi'), [$recipient]);

    $deliveries = RecordingChannel::deliveries();

    expect($deliveries)->toHaveCount(1)
        ->and($deliveries[0]['message']->subject)->toBe('Override for hi')
        ->and($deliveries[0]['message']->body)->toContain('Overridden body: hi');
});

// A subject ending in a word character (the common case — no trailing echo)
// once vanished: `@endsection` glued to it is not a directive for Blade's
// `\B@` tokenizer, so the section never closed and the body swallowed it.
it('keeps a plain-text override subject out of the body', function (): void {
    NotificationTemplate::query()->create([
        'type' => 'test.recorded',
        'format' => Format::Text,
        'locale' => 'en',
        'subject' => 'Plain subject',
        'body' => 'Body only',
    ]);

    Notifications::send('test.recorded', new TestNotificationPayload('hi'), [new Recipient(Audience::Customer, 'en', email: 'ana@example.test')]);

    $message = RecordingChannel::deliveries()[0]['message'];

    expect($message->subject)->toBe('Plain subject')
        ->and(trim($message->body))->toBe('Body only');
});

it('does not apply a stored override registered for a different locale', function (): void {
    NotificationTemplate::query()->create([
        'type' => 'test.recorded',
        'format' => Format::Text,
        'locale' => 'en',
        'subject' => 'Override for {{ $message }}',
        'body' => 'Overridden body: {{ $message }}',
    ]);

    $recipient = new Recipient(Audience::Customer, 'uk', email: 'ana@example.test');

    Notifications::send('test.recorded', new TestNotificationPayload('hi'), [$recipient]);

    $deliveries = RecordingChannel::deliveries();

    expect($deliveries)->toHaveCount(1)
        ->and($deliveries[0]['message']->subject)->toBe('Hello hi')
        ->and($deliveries[0]['message']->body)->toContain('Body: hi');
});

it('falls back to the built-in view and logs a warning when a stored override fails to render', function (): void {
    Log::spy();

    NotificationTemplate::query()->create([
        'type' => 'test.recorded',
        'format' => Format::Text,
        'locale' => 'en',
        'subject' => 'Broken',
        'body' => '@if($message)unclosed',
    ]);

    $recipient = new Recipient(Audience::Customer, 'en', email: 'ana@example.test');

    Notifications::send('test.recorded', new TestNotificationPayload('hi'), [$recipient]);

    $deliveries = RecordingChannel::deliveries();

    expect($deliveries)->toHaveCount(1)
        ->and($deliveries[0]['message']->subject)->toBe('Hello hi')
        ->and($deliveries[0]['message']->body)->toContain('Body: hi');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            expect($message)->toBe('notifications.template_override_failed')
                ->and($context['type'])->toBe('test.recorded')
                ->and($context['format'])->toBe('text')
                ->and($context['locale'])->toBe('en')
                ->and($context['error'])->toBeString();

            return true;
        });
});
