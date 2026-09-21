<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures\BlockTypes;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;

/**
 * Typed block whose resolve() shape reuses a @phpstan-type alias, exercising
 * decision 1 (alias source of truth) and decision 2 (aliases emitted before
 * the block that first refers to them).
 *
 * @phpstan-type CtaLink array{label: string, href: string}
 */
class TypedAliasBlock extends Block
{
    public function kind(): string
    {
        return 'TypedAlias';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{title: string, cta: CtaLink}
     */
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return ['title' => '', 'cta' => ['label' => '', 'href' => '']];
    }
}
