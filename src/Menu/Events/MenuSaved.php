<?php

declare(strict_types=1);

namespace Tbtop\Cms\Menu\Events;

use Tbtop\Cms\Menu\Models\Menu;

/**
 * Raised whenever a menu finishes saving (create or update). A typical
 * subscriber invalidates a cached navigation payload.
 */
final class MenuSaved
{
    public function __construct(
        public readonly Menu $menu,
        public readonly bool $wasCreated,
    ) {}
}
