<?php

declare(strict_types=1);

namespace Tbtop\Cms\Notifications;

/** The rendering shape a channel consumes: full HTML, plain text, or a short message (chat/push). */
enum Format: string
{
    case Html = 'html';
    case Text = 'text';
    case Short = 'short';
}
