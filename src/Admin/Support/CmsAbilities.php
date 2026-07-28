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
    public static function for(string $ability): ?string
    {
        return self::enabled() ? $ability : null;
    }

    public static function enabled(): bool
    {
        return (bool) config('tbtop-cms.roles', false);
    }
}
