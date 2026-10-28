<?php

declare(strict_types=1);

namespace Tbtop\Cms\Collection\Events;

use Tbtop\Cms\Collection\Models\Entry;

final class EntryDeleted
{
    public function __construct(
        public readonly Entry $entry,
    ) {}
}
