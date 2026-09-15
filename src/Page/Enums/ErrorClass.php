<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Enums;

/**
 * The two system error pages a host can edit: one per HTTP status class,
 * not one per status code (522 vs 500 is not worth a separate page).
 */
enum ErrorClass: string
{
    case ClientError = '4xx';
    case ServerError = '5xx';

    public static function fromStatus(int $status): ?self
    {
        return match (intdiv($status, 100)) {
            4 => self::ClientError,
            5 => self::ServerError,
            default => null,
        };
    }
}
