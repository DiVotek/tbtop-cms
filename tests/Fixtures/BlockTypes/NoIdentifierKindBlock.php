<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures\BlockTypes;

use Tbtop\Cms\Tests\Fixtures\Discovery\BetaBlock;

/** A kind with no letters or digits: nothing is left to name a component after. */
class NoIdentifierKindBlock extends BetaBlock
{
    public function kind(): string
    {
        return '--';
    }
}
