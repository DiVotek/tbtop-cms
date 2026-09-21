<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures\BlockTypes;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;

/**
 * Declares a `Shared` alias with a different shape than
 * AliasConflictTwoBlock's — a registry-wide name collision (decision 6).
 *
 * @phpstan-type Shared array{a: string}
 */
class AliasConflictOneBlock extends Block
{
    public function kind(): string
    {
        return 'AliasConflictOne';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{shared: Shared}
     */
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return ['shared' => ['a' => '']];
    }
}
