<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications;

enum NotificationLogStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
}
