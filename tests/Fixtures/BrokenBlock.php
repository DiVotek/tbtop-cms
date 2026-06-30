<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use RuntimeException;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;

class BrokenBlock extends Block
{
    public function kind(): string
    {
        return 'Broken';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolve(array $input, ResolveContext $ctx): array
    {
        throw new RuntimeException('boom');
    }
}
