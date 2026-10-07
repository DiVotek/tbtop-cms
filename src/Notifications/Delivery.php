<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications;

/** The outcome of one (recipient, channel) delivery attempt, as returned by Notifications::send(). */
final readonly class Delivery
{
    public function __construct(
        public string $type,
        public string $channel,
        public Recipient $recipient,
        public NotificationLogStatus $status,
        public ?string $error = null,
        public DeliveryStage $stage = DeliveryStage::Send,
    ) {}

    public function failed(): bool
    {
        return $this->status === NotificationLogStatus::Failed;
    }
}
