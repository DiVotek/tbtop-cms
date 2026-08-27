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
    ) {}

    public function send(object $notifiable, TemplatedNotification $notification): void
    {
        $channel = $this->registry->channel($notification->channelKey);
        $recipient = $notification->recipient;

        $log = NotificationLog::query()->create([
            'type' => $notification->type,
            'channel' => $notification->channelKey,
            'audience' => $recipient->audience,
            'recipient' => $this->recipientLabel($recipient),
            'locale' => $recipient->locale,
            'status' => NotificationLogStatus::Pending,
        ]);

        try {
            $channel->send($notification->rendered, $recipient, $this->settings);
        } catch (Throwable $e) {
            $log->forceFill(['status' => NotificationLogStatus::Failed, 'error' => $e->getMessage()])->save();

            $this->events->dispatch(new NotificationFailed(
                $notification->type,
                $notification->channelKey,
                $recipient,
                $e->getMessage(),
            ));

            return;
        }

        $log->forceFill(['status' => NotificationLogStatus::Sent, 'sent_at' => now()])->save();

        $this->events->dispatch(new NotificationSent($notification->type, $notification->channelKey, $recipient));
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
