<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures\BlockTypes;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;

/**
 * @phpstan-type CtaLink array{label: string, href: string}
 *
 * @phpstan-import-type Money from CyclicImportSource
 */
class CyclicImportBlock extends Block
{
    public function kind(): string
    {
        return 'CyclicImport';
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
