<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;

/**
 * Emits the page's heading and body through ResolveContext::pageText() /
 * pageBodyHtml(), so a test can assert the locale fallback they apply.
 */
class PageTextBlock extends Block
{
    public function kind(): string
    {
        return 'PageText';
    }

    /** @return list<Field> */
    public function form(S $s): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    #[\Override]
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return ['heading' => $ctx->pageText('heading'), 'bodyHtml' => $ctx->pageBodyHtml()];
    }
}
