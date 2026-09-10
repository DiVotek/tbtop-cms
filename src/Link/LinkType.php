<?php

declare(strict_types=1);

namespace Tbtop\Cms\Link;

/**
 * The four link targets a `link` field can store. The backing strings are the
 * stored value's `type` key and cross the wire to the client's type switch —
 * renaming one breaks every saved link of that type.
 */
enum LinkType: string
{
    case Page = 'page';
    case External = 'external';
    case Email = 'email';
    case Phone = 'phone';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
