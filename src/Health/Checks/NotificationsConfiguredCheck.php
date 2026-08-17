<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;
use Tbtop\Cms\Settings\Settings;

/**
 * Mail/Telegram notifications each have an independent enabled flag plus
 * the settings they need to actually deliver (see NotificationSettingsPage's
 * field map). SubmissionNotifier already reports a misconfigured channel as
 * a Failed submission per-occurrence (see its docblock), but only after a
 * form is actually submitted — this check surfaces the same misconfiguration
 * proactively, from the health page, before the first real submission hits it.
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
        if (! $this->isTruthy($this->settings->get('notifications.mail.enabled'))) {
            return [];
        }

        $problems = [];

        if (! $this->hasRecipients($this->settings->get('notifications.mail.recipients'))) {
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
        if (! $this->isTruthy($this->settings->get('notifications.telegram.enabled'))) {
            return [];
        }

        $problems = [];

        if (! $this->isNonEmptyString($this->settings->get('notifications.telegram.bot_token'))) {
            $problems[] = __('tbtop-cms::health.checks.notifications_configured.hints.telegram_no_token');
        }

        if (! $this->hasRecipients($this->settings->get('notifications.telegram.chat_ids'))) {
            $problems[] = __('tbtop-cms::health.checks.notifications_configured.hints.telegram_no_chat_ids');
        }

        return $problems;
    }

    private function isTruthy(mixed $value): bool
    {
        return $value === true || $value === '1' || $value === 1;
    }

    private function isNonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /** Recipients/chat ids are stored as a newline-joined string (see Recipients::parse/ChatIds). */
    private function hasRecipients(mixed $value): bool
    {
        return $this->isNonEmptyString($value);
    }
}
