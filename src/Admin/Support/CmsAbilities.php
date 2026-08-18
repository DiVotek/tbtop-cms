<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Support;

/**
 * The tbtop-cms.* section ability an admin page declares from can().
 *
 * The package never defines these gates; answering them is the host's job,
 * switched on by tbtop-cms.roles. Returning null makes the admin package
 * skip the gate entirely — AuthorizesPage and NavBuilder both treat a null
 * ability as "no check", leaving sections open to anyone EnsureCmsAdmin
 * let through.
 */
final class CmsAbilities
{
    /**
     * Every section ability a page's can() may return. Exists so a host or a
     * roles-extension package can enumerate the gates it must define,
     * instead of hunting the literals scattered across admin pages.
     *
     * @var list<string>
     */
    public const ALL = [
        'tbtop-cms.pages',
        'tbtop-cms.media',
        'tbtop-cms.submissions',
        'tbtop-cms.redirects',
        'tbtop-cms.settings',
        'tbtop-cms.design',
        'tbtop-cms.languages',
        'tbtop-cms.users',
        'tbtop-cms.audit',
    ];

    public static function for(string $ability): ?string
    {
        return self::enabled() ? $ability : null;
    }

    public static function enabled(): bool
    {
        return (bool) config('tbtop-cms.roles', false);
    }
}
