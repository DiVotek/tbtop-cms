<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;

class EmptyKindBlock extends Block
{
    public function kind(): string
    {
        return '';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [];
    }
}
