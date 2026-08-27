<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications;

use Illuminate\Notifications\Notification;

/**
 * The Laravel notification sent once per (recipient, enabled channel) pair
 * by Notifications::send(). Delivery is not one of Laravel's built-in
 * channels: via() names NotificationChannelDriver, a container-resolved
 * adapter (Laravel's documented "custom channel class" mechanism) that looks
 * up $channelKey in the registry and calls Channel::send().
 *
 * Deliberately not ShouldQueue: see Notifications::send()'s docblock for why
 * this dispatch is synchronous by design, not by the absence of a queue.
 */
final class TemplatedNotification extends Notification
{
    public function __construct(
        public readonly string $type,
        public readonly string $channelKey,
        public readonly Rendered $rendered,
        public readonly Recipient $recipient,
    ) {}

    /** @return list<class-string> */
    public function via(object $notifiable): array
    {
        return [NotificationChannelDriver::class];
    }
}
