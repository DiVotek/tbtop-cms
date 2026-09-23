<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures\BlockTypes;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;

class NestedTraitAliasBlock extends Block
{
    use NestedOuterAliasTrait;

    public function kind(): string
    {
        return 'NestedTraitAlias';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{pair: Pair}
     */
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return ['pair' => ['a' => '', 'b' => '']];
    }
}
