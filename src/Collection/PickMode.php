<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection;

/** How a block's `collection` field picks its entries; the editor chooses one per block. */
enum PickMode: string
{
    case All = 'all';
    case Latest = 'latest';
    case Related = 'related';
    case Manual = 'manual';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $mode): string => $mode->value, self::cases());
    }
}
