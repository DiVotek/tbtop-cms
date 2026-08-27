<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications\Events;

use Tbtop\Cms\Notifications\Recipient;

/** Raised after one channel fails to deliver one notification to one recipient; other channels still run. */
final readonly class NotificationFailed
{
    public function __construct(
        public string $type,
        public string $channel,
        public Recipient $recipient,
        public string $error,
    ) {}
}
