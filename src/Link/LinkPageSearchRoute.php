<?php

declare(strict_types=1);

namespace Tbtop\Cms\Link;

/** The one place the page-search path is spelled: CmsServiceProvider registers it, the Link field serializes it. */
final class LinkPageSearchRoute
{
    public const NAME = 'tbtop.cms.link.page-search';

    public const RELATIVE_PATH = 'link/page-search';

    /** Root-relative on purpose — the admin client posts through its own base-URL-less JSON client. */
    public static function path(): string
    {
        $prefix = trim((string) config('tbtop-cms.admin.prefix', 'admin'), '/');

        return '/' . ($prefix === '' ? '' : $prefix . '/') . self::RELATIVE_PATH;
    }
}
