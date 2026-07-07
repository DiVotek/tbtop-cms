<?php

declare(strict_types=1);

namespace Tbtop\Cms\Layout;

class Slots
{
    public const HEADER = 'header';

    public const FOOTER = 'footer';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::HEADER, self::FOOTER];
    }
}
