<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications;

/**
 * Carries each delivery outcome from NotificationChannelDriver back to the
 * Notifications::send() call that caused it. The driver runs inside
 * Laravel's Notification::sendNow(), which discards whatever a channel class
 * returns, so the outcome cannot travel back as a return value.
 *
 * Scoped to one send() call: send() opens a frame, drains it, and closes it
 * in a finally. Frames nest (a channel that itself sends a notification) so
 * an inner send() never steals the outer call's deliveries.
 */
final class DeliveryRecorder
{
    /** @var list<list<Delivery>> */
    private array $frames = [];

    public function begin(): void
    {
        $this->frames[] = [];
    }

    public function record(Delivery $delivery): void
    {
        if ($this->frames === []) {
            return;
        }

        $this->frames[array_key_last($this->frames)][] = $delivery;
    }

    /** @return list<Delivery> */
    public function end(): array
    {
        return array_pop($this->frames) ?? [];
    }
}
