<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;

class HeroBlock extends Block
{
    public function kind(): string
    {
        return 'Hero';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [$s->text('title')->translatable()];
    }
}
