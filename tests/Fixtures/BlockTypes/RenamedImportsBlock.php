<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures\BlockTypes;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;

/**
 * @phpstan-import-type Item from ItemSourceA as AItem
 * @phpstan-import-type Item from ItemSourceB as BItem
 */
class RenamedImportsBlock extends Block
{
    public function kind(): string
    {
        return 'RenamedImports';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{first: AItem, second: BItem}
     */
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return ['first' => ['a' => ''], 'second' => ['b' => 0]];
    }
}
