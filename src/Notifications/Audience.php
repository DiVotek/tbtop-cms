<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications;

enum Audience: string
{
    case Customer = 'customer';
    case Staff = 'staff';
}
