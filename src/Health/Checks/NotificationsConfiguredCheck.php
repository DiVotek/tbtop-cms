<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;
use Tbtop\Cms\Mail\Recipients;
use Tbtop\Cms\Notifications\Notifications;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Telegram\ChatIds;

/**
 * Mail/Telegram each need settings beyond membership in form.submission's
 * channel list to actually deliver (see NotificationSettingsPage's field
 * map) — a channel is checked here only when it's in
 * Notifications::enabledChannels('form.submission'). SubmissionNotifier
 * already reports a misconfigured channel as a Failed submission
 * per-occurrence (see its docblock), but only after a form is actually
 * submitted — this check surfaces the same misconfiguration proactively,
 * from the health page, before the first real submission hits it. Recipients
 * and chat ids go through the same parsers delivery uses.
 */
final class NotificationsConfiguredCheck implements HealthCheck
{
    public function __construct(private readonly Settings $settings) {}

    public function id(): string
    {
        return 'notifications_configured';
    }

    public function run(): HealthCheckResult
    {
        $problems = [
            ...$this->mailProblems(),
            ...$this->telegramProblems(),
        ];

        if ($problems === []) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Ok,
                labelKey: 'tbtop-cms::health.checks.notifications_configured.label',
                messageKey: 'tbtop-cms::health.checks.notifications_configured.ok',
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Warning,
            labelKey: 'tbtop-cms::health.checks.notifications_configured.label',
            messageKey: 'tbtop-cms::health.checks.notifications_configured.warning',
            hintKey: 'tbtop-cms::health.checks.notifications_configured.hints.combined',
            hintReplace: ['details' => implode(' ', $problems)],
        );
    }

    /** @return list<string> */
    private function mailProblems(): array
    {
        if (! in_array('mail', Notifications::enabledChannels('form.submission'), true)) {
            return [];
        }

        $problems = [];

        if (Recipients::parse($this->settings->get('notifications.mail.recipients')) === []) {
            $problems[] = __('tbtop-cms::health.checks.notifications_configured.hints.mail_no_recipients');
        }

        if (! $this->isNonEmptyString($this->settings->get('mail.from_address')) && ! $this->isNonEmptyString(config('mail.from.address'))) {
            $problems[] = __('tbtop-cms::health.checks.notifications_configured.hints.mail_no_from');
        }

        return $problems;
    }

    /** @return list<string> */
    private function telegramProblems(): array
    {
        if (! in_array('telegram', Notifications::enabledChannels('form.submission'), true)) {
            return [];
        }

        $problems = [];

        if (! $this->isNonEmptyString($this->settings->get('notifications.telegram.bot_token'))) {
            $problems[] = __('tbtop-cms::health.checks.notifications_configured.hints.telegram_no_token');
        }

        if (ChatIds::parse($this->settings->get('notifications.telegram.chat_ids')) === []) {
            $problems[] = __('tbtop-cms::health.checks.notifications_configured.hints.telegram_no_chat_ids');
        }

        return $problems;
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
