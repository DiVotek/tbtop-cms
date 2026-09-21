<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures\BlockTypes;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;

/**
 * A nested `resource` type has no zod mapping — a hard command error naming
 * the block kind and key path (decision 3), unlike an untyped top level
 * (decision 4) which degrades instead.
 */
class UnsupportedShapeBlock extends Block
{
    public function kind(): string
    {
        return 'UnsupportedShape';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{handle: resource}
     */
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return ['handle' => $input['handle'] ?? null];
    }
}
