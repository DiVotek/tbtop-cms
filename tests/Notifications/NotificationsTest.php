<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Tbtop\Cms\Notifications\Audience;
use Tbtop\Cms\Notifications\Delivery;
use Tbtop\Cms\Notifications\Events\NotificationFailed;
use Tbtop\Cms\Notifications\Events\NotificationSent;
use Tbtop\Cms\Notifications\Format;
use Tbtop\Cms\Notifications\Models\NotificationLog;
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
