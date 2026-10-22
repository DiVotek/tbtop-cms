<?php

declare(strict_types=1);

namespace Tbtop\Cms\Form;

use Illuminate\Support\Facades\Context;
use Tbtop\Cms\Form\Models\FormSubmission;
use Tbtop\Cms\Form\Notifications\FormSubmissionPayloadFactory;
use Tbtop\Cms\Form\Notifications\FormSubmissionRecipients;
use Tbtop\Cms\Notifications\Delivery;
use Tbtop\Cms\Notifications\DeliveryStage;
use Tbtop\Cms\Notifications\NotificationRegistry;
use Tbtop\Cms\Notifications\Notifications;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Settings;
use Throwable;

/**
 * Thin caller over Notifications::send('form.submission', ...): builds the
 * recipient list and payload, sends, and turns the per-channel outcome
 * (Notifications::send() is synchronous — see its docblock) into
 * FormSubmission's status. Shared by NotifiesAdminsOfSubmission (the
 * SubmissionReceived listener, the normal path, via notify()) and
 * SubmissionRetrier (replays the same fan-out via deliver() after a
 * submission was previously marked Failed, folding its errors into the one
 * release it makes) so both judge the fan-out by the exact same rule.
 *
 * Status semantics: Handled when every enabled channel succeeds — including
 * when no optional channel is enabled at all (bell-only is a complete,
 * successful fan-out, not a degraded one). Failed, with every distinct
 * channel error joined into one message, as soon as any enabled channel
 * throws or its template fails to render; a disabled channel is skipped
 * silently and never contributes an error.
 * Notifications::send() itself never throws a per-channel failure (it logs
 * it to notification_log and moves on to the next channel/recipient) — it
 * returns one Delivery per attempted (recipient, channel) pair instead, and
 * this class turns the failed ones into the error message. The return value
 * is the only sound source: notification_log is shared by every concurrent
 * request and has no column tying a row to the submission that caused it, so
 * reading it back by id window attributed a concurrent submission's failure
 * to this one.
 */
class SubmissionNotifier
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Any exception after the claim — not only a channel's — ends the row
     * Failed with its message and goes to report(); it is never rethrown
     * (this runs in app()->terminating(), after the visitor's response).
     */
    public function notify(FormSubmission $submission, Page $page): void
    {
        if (! SubmissionClaim::take($submission, SubmissionStatus::Pending)) {
            return;
        }

        $outcome = ['status' => SubmissionStatus::Failed->value, 'error' => null];

        try {
            $errors = $this->deliver($submission, $page);

            $outcome = [
                'status' => ($errors === [] ? SubmissionStatus::Handled : SubmissionStatus::Failed)->value,
                'error' => $errors === [] ? null : implode(' | ', $errors),
            ];
        } catch (Throwable $e) {
            // The original exception, so host trackers group and filter it by its
            // own type; the id rides along in the log context.
            Context::add('submission_id', $submission->id);
            report($e);
            Context::forget('submission_id');

            $outcome = ['status' => SubmissionStatus::Failed->value, 'error' => $e->getMessage()];
        } finally {
            SubmissionClaim::release($submission, $outcome);
        }
    }

    /**
     * Runs the fan-out and returns every distinct channel error, without
     * touching the claim: the caller holds it (SubmissionClaim::take) and
     * releases it once, with the outcome of everything it ran. Never writes
     * handled_at — that column belongs to the block handler's own success.
     *
     * @return list<string>
     */
    public function deliver(FormSubmission $submission, Page $page): array
    {
        $built = FormSubmissionRecipients::build($this->settings);

        // Two recipients of one broken template fail with the same text;
        // the operator needs it once.
        return array_values(array_unique([
            ...$this->misconfigurationErrors($built),
            ...$this->send($submission, $page, $built),
        ]));
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
        // Recipients read the admin UI locale, not the page's: the payload's
        // own translated text (action label, date format) follows each group.
        $byLocale = [];
        foreach ($built->recipients as $recipient) {
            $byLocale[$recipient->locale][] = $recipient;
        }

        $deliveries = [];
        foreach ($byLocale as $locale => $recipients) {
            $payload = FormSubmissionPayloadFactory::make($submission, $page, (string) $locale);
            array_push($deliveries, ...Notifications::send('form.submission', $payload, $recipients));
        }

        return array_values(array_map(
            $this->errorFor(...),
            array_filter($deliveries, fn (Delivery $delivery): bool => $delivery->failed()),
        ));
    }

    /**
     * A send failure reads through the channel's own errors.{channel}
     * prefix; a render failure has its own prefix naming the channel — the
     * channel never ran, so its send prefix would mislead. A host channel
     * with no errors.{channel} key gets the generic errors.channel text.
     */
    private function errorFor(Delivery $delivery): string
    {
        if ($delivery->stage === DeliveryStage::Render) {
            return __('tbtop-cms::notifications.errors.render', [
                'channel' => $this->channelName($delivery->channel),
                'error' => (string) $delivery->error,
            ]);
        }

        $translationKey = "tbtop-cms::notifications.errors.{$delivery->channel}";

        if (! trans()->has($translationKey)) {
            return __('tbtop-cms::notifications.errors.channel', [
                'channel' => $this->channelName($delivery->channel),
                'error' => (string) $delivery->error,
            ]);
        }

        return __($translationKey, ['error' => (string) $delivery->error]);
    }

    /** The short channel name; a host channel without one falls back to its label(). */
    private function channelName(string $key): string
    {
        $translationKey = "tbtop-cms::notifications.channels.{$key}";

        if (trans()->has($translationKey)) {
            return __($translationKey);
        }

        $registry = app(NotificationRegistry::class);

        return $registry->hasChannel($key) ? $registry->channel($key)->label() : $key;
    }
}
