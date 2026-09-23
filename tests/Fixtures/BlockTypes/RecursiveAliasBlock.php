<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures\BlockTypes;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;

/**
 * @phpstan-type Node array{next: Node|null}
 */
class RecursiveAliasBlock extends Block
{
    public function kind(): string
    {
        return 'RecursiveAlias';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{root: Node}
     */
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return ['root' => ['next' => null]];
    }
}
