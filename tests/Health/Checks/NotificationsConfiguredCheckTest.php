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

it('warns when mail notifications are enabled without recipients or a from-address', function (): void {
    app(Settings::class)->set('notifications.mail.enabled', true);

    $result = app(NotificationsConfiguredCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->hint())->toContain(__('tbtop-cms::health.checks.notifications_configured.hints.mail_no_recipients'));
});

it('is ok when mail notifications are enabled with recipients and a from-address', function (): void {
    app(Settings::class)->set('notifications.mail.enabled', true);
    app(Settings::class)->set('notifications.mail.recipients', 'admin@example.com');
    app(Settings::class)->set('mail.from_address', 'noreply@example.com');

    $result = app(NotificationsConfiguredCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
});

it('warns when telegram notifications are enabled without a bot token or chat ids', function (): void {
    app(Settings::class)->set('notifications.telegram.enabled', true);

    $result = app(NotificationsConfiguredCheck::class)->run();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->hint())
        ->toContain(__('tbtop-cms::health.checks.notifications_configured.hints.telegram_no_token'))
        ->toContain(__('tbtop-cms::health.checks.notifications_configured.hints.telegram_no_chat_ids'));
});
