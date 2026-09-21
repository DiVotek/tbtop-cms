<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures\BlockTypes;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;
use Tbtop\Cms\Tests\Fixtures\BlockTypes\Sources\RangeSource as Src;

/**
 * @phpstan-import-type Money from ImportedAliasSource
 * @phpstan-import-type Range from Src
 */
class ShortNameImportBlock extends Block
{
    public function kind(): string
    {
        return 'ShortNameImport';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{price: Money, range: Range}
     */
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return ['price' => ['amount' => 0, 'currency' => ''], 'range' => ['from' => 0, 'to' => 0]];
    }
}
