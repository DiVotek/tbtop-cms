<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications;

use Illuminate\Contracts\Events\Dispatcher;
use Tbtop\Cms\Notifications\Events\NotificationFailed;
use Tbtop\Cms\Notifications\Events\NotificationSent;
use Tbtop\Cms\Notifications\Models\NotificationLog;
use Tbtop\Cms\Settings\Settings;
use Throwable;

/**
 * The one Laravel notification-channel class every TemplatedNotification
 * routes through (see its via()). Resolves the actual Channel by key from
 * the registry, delegates the send, and writes the delivery log row and
 * NotificationSent/NotificationFailed event this class is the sole owner of
 * — a Channel implementation never touches the log or these events itself.
 */
final readonly class NotificationChannelDriver
{
    public function __construct(
        private NotificationRegistry $registry,
        private Settings $settings,
        private Dispatcher $events,
        private DeliveryRecorder $recorder,
    ) {}

    public function send(object $notifiable, TemplatedNotification $notification): void
    {
        $channel = $this->registry->channel($notification->channelKey);
        $recipient = $notification->recipient;

        $log = $this->openLog($notification->type, $notification->channelKey, $recipient);

        try {
            $channel->send($notification->rendered, $recipient, $this->settings);
        } catch (Throwable $e) {
            $this->fail($log, $notification->type, $notification->channelKey, $recipient, $e->getMessage(), DeliveryStage::Send);

            return;
        }

        $log->forceFill(['status' => NotificationLogStatus::Sent, 'sent_at' => now()])->save();

        $this->recorder->record(new Delivery(
            $notification->type,
            $notification->channelKey,
            $recipient,
            NotificationLogStatus::Sent,
        ));

        $this->events->dispatch(new NotificationSent($notification->type, $notification->channelKey, $recipient));
    }

    /**
     * Records a pair that failed before reaching a channel (Notifications::send
     * calls it when the template throws), through the same log row, Delivery
     * and NotificationFailed a send failure gets — so this class stays their
     * only writer.
     */
    public function recordFailure(string $type, string $channelKey, Recipient $recipient, string $error, DeliveryStage $stage = DeliveryStage::Send): void
    {
        $this->fail($this->openLog($type, $channelKey, $recipient), $type, $channelKey, $recipient, $error, $stage);
    }

    private function openLog(string $type, string $channelKey, Recipient $recipient): NotificationLog
    {
        return NotificationLog::query()->create([
            'type' => $type,
            'channel' => $channelKey,
            'audience' => $recipient->audience,
            'recipient' => $this->recipientLabel($recipient),
            'locale' => $recipient->locale,
            'status' => NotificationLogStatus::Pending,
        ]);
    }

    private function fail(NotificationLog $log, string $type, string $channelKey, Recipient $recipient, string $error, DeliveryStage $stage): void
    {
        $log->forceFill(['status' => NotificationLogStatus::Failed, 'error' => $error])->save();

        $this->recorder->record(new Delivery($type, $channelKey, $recipient, NotificationLogStatus::Failed, $error, $stage));

        $this->events->dispatch(new NotificationFailed($type, $channelKey, $recipient, $error, $stage));
    }

    private function recipientLabel(Recipient $recipient): string
    {
        return $recipient->email
            ?? $recipient->phone
            ?? ($recipient->notifiable !== null ? $this->notifiableLabel($recipient->notifiable) : 'unknown');
    }

    private function notifiableLabel(object $notifiable): string
    {
        if (method_exists($notifiable, 'getKey')) {
            return (string) $notifiable->getKey();
        }

        return $notifiable::class;
    }
}
