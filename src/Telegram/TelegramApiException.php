<?php

declare(strict_types=1);

namespace Tbtop\Cms\Telegram;

use RuntimeException;
use Throwable;

final class TelegramApiException extends RuntimeException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }
}
