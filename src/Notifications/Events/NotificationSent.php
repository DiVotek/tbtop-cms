<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications\Events;

use Tbtop\Cms\Notifications\Recipient;

/** Raised after one channel delivers one notification to one recipient. */
final readonly class NotificationSent
{
    public function __construct(
        public string $type,
        public string $channel,
        public Recipient $recipient,
    ) {}
}
