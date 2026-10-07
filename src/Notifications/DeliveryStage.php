<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications;

/** Where a (recipient, channel) pair failed: rendering its template, or the channel's own send. */
enum DeliveryStage: string
{
    case Render = 'render';
    case Send = 'send';
}
