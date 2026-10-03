<?php

declare(strict_types=1);

namespace Tbtop\Cms\SharedBlock\Events;

use Tbtop\Cms\SharedBlock\Models\SharedBlock;

final class SharedBlockSaved
{
    public function __construct(
        public readonly SharedBlock $block,
    ) {}
}
