<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications\Events;

use Tbtop\Cms\Notifications\DeliveryStage;
use Tbtop\Cms\Notifications\Recipient;

/**
 * Raised after one (recipient, channel) pair fails — its template failed to
 * render (stage Render) or the channel threw on send (stage Send); the other
 * pairs still run.
 */
final readonly class NotificationFailed
{
    public function __construct(
        public string $type,
        public string $channel,
        public Recipient $recipient,
        public string $error,
        public DeliveryStage $stage = DeliveryStage::Send,
    ) {}
}
