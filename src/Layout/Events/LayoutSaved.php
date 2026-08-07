<?php

declare(strict_types=1);

namespace Tbtop\Cms\Layout\Events;

use Tbtop\Cms\Layout\Models\Layout;

/**
 * Raised whenever a layout slot is saved. A typical subscriber invalidates a
 * cached rendering of that slot.
 */
final class LayoutSaved
{
    public function __construct(
        public readonly Layout $layout,
    ) {}
}
