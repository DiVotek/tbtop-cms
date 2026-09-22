<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures\BlockTypes;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;

/**
 * @phpstan-import-type Coord from CoordAliasTrait as Point
 */
class TraitImportBlock extends Block
{
    use CoordAliasTrait;

    public function kind(): string
    {
        return 'TraitImport';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{at: Point}
     */
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return ['at' => ['x' => 0, 'y' => 0]];
    }
}
