<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Health\Checks\NotificationsConfiguredCheck;
use Tbtop\Cms\Health\HealthStatus;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

it('is ok when no notification channel is enabled', function (): void {
    $result = app(NotificationsConfiguredCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
});

it('warns when mail is in the channel list without recipients or a from-address', function (): void {
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'mail']);

    $result = app(NotificationsConfiguredCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->hint())->toContain(__('tbtop-cms::health.checks.notifications_configured.hints.mail_no_recipients'));
});

it('is ok when mail is in the channel list with recipients and a from-address', function (): void {
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'mail']);
    app(Settings::class)->set('notifications.mail.recipients', 'admin@example.com');
    app(Settings::class)->set('mail.from_address', 'noreply@example.com');

    $result = app(NotificationsConfiguredCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
});

it('warns when telegram is in the channel list without a bot token or chat ids', function (): void {
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'telegram']);

    $result = app(NotificationsConfiguredCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->hint())
        ->toContain(__('tbtop-cms::health.checks.notifications_configured.hints.telegram_no_token'))
        ->toContain(__('tbtop-cms::health.checks.notifications_configured.hints.telegram_no_chat_ids'));
});

it('warns when the stored recipients and chat ids hold nothing delivery can use', function (): void {
    app(Settings::class)->set('notifications.form.submission.channels', ['bell', 'mail', 'telegram']);
    app(Settings::class)->set('notifications.mail.recipients', 'not-an-email');
    app(Settings::class)->set('mail.from_address', 'noreply@example.com');
    app(Settings::class)->set('notifications.telegram.bot_token', 'a-token');
    app(Settings::class)->set('notifications.telegram.chat_ids', '@channel # Sales');

    $result = app(NotificationsConfiguredCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->hint())
        ->toContain(__('tbtop-cms::health.checks.notifications_configured.hints.mail_no_recipients'))
        ->toContain(__('tbtop-cms::health.checks.notifications_configured.hints.telegram_no_chat_ids'));
});
