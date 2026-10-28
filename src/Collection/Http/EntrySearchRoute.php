<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection\Http;

/** The one place the entry-search path is spelled: AdminWiring registers it, the collection field serializes it. */
final class EntrySearchRoute
{
    public const string NAME = 'tbtop.cms.entry-search';

    public const string RELATIVE_PATH = 'entry-search';

    /** Root-relative on purpose — the admin client posts through its own base-URL-less JSON client. */
    public static function path(): string
    {
        $prefix = trim((string) config('tbtop-cms.admin.prefix', 'admin'), '/');

        return '/' . ($prefix === '' ? '' : $prefix . '/') . self::RELATIVE_PATH;
    }
}
