<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form;

use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\Notifications\FormSubmissionPayloadFactory;
use Tbtop\Cms\Form\Notifications\FormSubmissionRecipients;
use Tbtop\Cms\Notifications\Models\NotificationLog;
use Tbtop\Cms\Notifications\NotificationLogStatus;
use Tbtop\Cms\Notifications\Notifications;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;

/**
 * Thin caller over Notifications::send('form.submission', ...): builds the
 * recipient list and payload, sends, and turns the per-channel outcome
 * (Notifications::send() is synchronous — see its docblock) into
 * FormSubmission's status. Shared by NotifiesAdminsOfSubmission (the
 * SubmissionReceived listener, the normal path) and SubmissionRetrier
 * (replays the same fan-out after a submission was previously marked
 * Failed) so both write the exact same delivery status from the exact same
 * rule.
 *
 * Status semantics: Handled when every enabled channel succeeds — including
 * when no optional channel is enabled at all (bell-only is a complete,
 * successful fan-out, not a degraded one). Failed, with every channel's
 * error joined into one message, as soon as any enabled channel throws; a
 * disabled channel is skipped silently and never contributes an error.
 * Notifications::send() itself never throws a per-channel failure (it logs
 * it to notification_log and moves on to the next channel/recipient) — this
 * class reads the log rows written during its own send() call to recover
 * those errors, since it is the only caller that needs the per-delivery
 * outcome back rather than just the log table. Reading the log rather than
 * listening for NotificationFailed avoids mutating the shared event
 * dispatcher for the duration of one call.
 */
class SubmissionNotifier
{
    public function __construct(private readonly Settings $settings) {}

    public function notify(FormSubmission $submission, Page $page): void
    {
        if (! SubmissionClaim::take($submission, SubmissionStatus::Pending)) {
            return;
        }

        $this->notifyClaimed($submission, $page);
    }

    /** Assumes the caller already holds the claim (SubmissionClaim::take). */
    public function notifyClaimed(FormSubmission $submission, Page $page): void
    {
        $built = FormSubmissionRecipients::build($this->settings, $submission->locale);

        $errors = [
            ...$this->misconfigurationErrors($built),
            ...$this->send($submission, $page, $built),
        ];

        if ($errors === []) {
            SubmissionClaim::release($submission, [
                'status' => SubmissionStatus::Handled->value,
                'handled_at' => $submission->handled_at ?? now(),
                'error' => null,
            ]);

            return;
        }

        SubmissionClaim::release($submission, [
            'status' => SubmissionStatus::Failed->value,
            'error' => implode(' | ', $errors),
        ]);
    }

    /** @return list<string> */
    private function misconfigurationErrors(FormSubmissionRecipients $built): array
    {
        $errors = [];

        if ($built->mailMisconfigured) {
            $errors[] = __('tbtop-cms::notifications.errors.mail_no_recipients');
        }

        if ($built->telegramMisconfigured) {
            $errors[] = __('tbtop-cms::notifications.errors.telegram_not_configured');
        }

        return $errors;
    }

    /** @return list<string> */
    private function send(FormSubmission $submission, Page $page, FormSubmissionRecipients $built): array
    {
        if ($built->recipients === []) {
            return [];
        }

        $payload = FormSubmissionPayloadFactory::make($submission, $page);
        $sinceId = (int) (NotificationLog::query()->max('id') ?? 0);

        Notifications::send('form.submission', $payload, $built->recipients);

        return NotificationLog::query()
            ->where('id', '>', $sinceId)
            ->where('type', 'form.submission')
            ->where('status', NotificationLogStatus::Failed)
            ->get()
            ->map(fn (NotificationLog $log): string => __("tbtop-cms::notifications.errors.{$log->channel}", ['error' => (string) $log->error]))
            ->all();
    }
}
