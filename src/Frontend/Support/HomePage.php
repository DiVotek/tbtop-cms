<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Support;

use Tbtop\Cms\Page\Models\Page;

/**
 * The seeded site root: a system page with no parent and no error_class
 * (an error page is a system root too, but never the home page). Nullable
 * by contract — a host that never ran the installer has none.
 */
final class HomePage
{
    public static function find(): ?Page
    {
        return Page::query()
            ->where('is_system', true)
            ->whereNull('parent_id')
            ->whereNull('error_class')
            ->first();
    }
}
