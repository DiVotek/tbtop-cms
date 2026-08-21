<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form;

use Tbtop\Admin\Notifications\Notification;
use Tbtop\Admin\Notifications\NotificationAction;
use Tbtop\Cms\Admin\Support\AdminUsers;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\Support\LeadIdentity;
use Tbtop\Cms\Mail\CmsMailer;
use Tbtop\Cms\Mail\Recipients;
use Tbtop\Cms\Mail\SubmissionMail;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Telegram\ChatIds;
use Tbtop\Cms\Telegram\TelegramSubmissionNotifier;
use Throwable;

/**
 * Fan-out for a new (or retried) submission: a database notification for
 * every admin (always, no opt-out — SPEC decision for this rework), plus
 * mail and Telegram when their respective settings are enabled. Shared by
 * NotifiesAdminsOfSubmission (the SubmissionReceived listener, the normal
 * path) and SubmissionRetrier (replays the same fan-out after a submission
 * was previously marked Failed) so both write the exact same delivery
 * status from the exact same rule.
 *
 * Status semantics: Handled when every enabled channel succeeds — including
 * when no optional channel is enabled at all (bell-only is a complete,
 * successful fan-out, not a degraded one). Failed, with every channel's
 * error joined into one message, as soon as any enabled channel throws; a
 * disabled channel is skipped silently and never contributes an error.
 */
class SubmissionNotifier
{
    public function __construct(
        private readonly Settings $settings,
        private readonly CmsMailer $mailer,
        private readonly TelegramSubmissionNotifier $telegram,
    ) {}

    public function notify(FormSubmission $submission, Page $page): void
    {
        $errors = [
            ...$this->notifyBell($submission),
            ...$this->notifyMail($submission, $page),
            ...$this->notifyTelegram($submission, $page),
        ];

        if ($errors === []) {
            $submission->forceFill(['status' => SubmissionStatus::Handled, 'handled_at' => $submission->handled_at ?? now(), 'error' => null])->save();

            return;
        }

        $submission->forceFill(['status' => SubmissionStatus::Failed, 'error' => implode(' | ', $errors)])->save();
    }

    /** @return list<string> */
    private function notifyBell(FormSubmission $submission): array
    {
        $admins = AdminUsers::all();

        if ($admins->isEmpty()) {
            return [];
        }

        try {
            Notification::make()
                ->title(__('tbtop-cms::notifications.bell.title'))
                ->body(LeadIdentity::label($submission->payload) ?? __('tbtop-cms::form-submissions.values.lead_unknown'))
                ->info()
                ->actions([
                    NotificationAction::make(__('tbtop-cms::notifications.bell.view'))
                        ->url(route('tbtop.cms.form-submission-page', ['submission' => $submission->id])),
                ])
                ->sendToDatabase($admins);
        } catch (Throwable $e) {
            return [__('tbtop-cms::notifications.errors.bell', ['error' => $e->getMessage()])];
        }

        return [];
    }

    /** @return list<string> */
    private function notifyMail(FormSubmission $submission, Page $page): array
    {
        if (! $this->settings->get('notifications.mail.enabled')) {
            return [];
        }

        $recipients = Recipients::parse($this->settings->get('notifications.mail.recipients'));

        // Enabled with nothing to send to is a misconfiguration, not a
        // success — reporting it keeps the delivery status honest instead of
        // showing "Handled" while nobody was mailed.
        if ($recipients === []) {
            return [__('tbtop-cms::notifications.errors.mail_no_recipients')];
        }

        try {
            $this->mailer->send(new SubmissionMail($submission, $this->pageName($page)), $recipients);
        } catch (Throwable $e) {
            return [__('tbtop-cms::notifications.errors.mail', ['error' => $e->getMessage()])];
        }

        return [];
    }

    /** @return list<string> */
    private function notifyTelegram(FormSubmission $submission, Page $page): array
    {
        if (! $this->settings->get('notifications.telegram.enabled')) {
            return [];
        }

        $token = $this->settings->get('notifications.telegram.bot_token');
        $chatIds = ChatIds::parse($this->settings->get('notifications.telegram.chat_ids'));

        // Same honesty rule as mail: enabled without a token or without any
        // chat to deliver to must surface as a failure, not a silent skip.
        if (! is_string($token) || trim($token) === '' || $chatIds === []) {
            return [__('tbtop-cms::notifications.errors.telegram_not_configured')];
        }

        $errors = $this->telegram->notify($token, $chatIds, $submission, $this->pageName($page));

        return array_map(
            static fn (string $error): string => __('tbtop-cms::notifications.errors.telegram', ['error' => $error]),
            $errors,
        );
    }

    private function pageName(Page $page): string
    {
        $name = $page->name;

        return is_string($name) && trim($name) !== '' ? $name : __('tbtop-cms::form-submissions.values.page_number', ['id' => $page->getKey()]);
    }
}
