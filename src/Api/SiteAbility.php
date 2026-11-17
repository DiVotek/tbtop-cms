<?php

declare(strict_types=1);

namespace Tbtop\Cms\Api;

/** What a token may do with the site's 404 log and redirects. Stored as the value, never implied by `pages:write` or `*`. */
enum SiteAbility: string
{
    case NotFoundRead = 'notfound:read';
    case NotFoundWrite = 'notfound:write';
    case RedirectsRead = 'redirects:read';
    case RedirectsWrite = 'redirects:write';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $ability): string => $ability->value, self::cases());
    }
}
