<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;

class LinkBlock extends Block
{
    public function kind(): string
    {
        return 'LinkBlock';
    }

    /** @return list<Field> */
    public function form(S $s): array
    {
        return [$s->link('cta')];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    #[\Override]
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return ['cta' => $ctx->link($input['cta'] ?? null)];
    }
}
