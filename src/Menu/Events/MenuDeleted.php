<?php

declare(strict_types=1);

namespace Tbtop\Cms\Menu\Events;

use Tbtop\Cms\Menu\Models\Menu;

/**
 * Raised after a menu is deleted. A typical subscriber invalidates a cached
 * navigation payload.
 */
final class MenuDeleted
{
    public function __construct(
        public readonly Menu $menu,
    ) {}
}
