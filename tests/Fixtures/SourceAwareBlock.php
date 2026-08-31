<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\ResolveContext;

/**
 * Emits ResolveContext::$source's string form in its props, so a frontend
 * test can assert the source actually reaches block resolution.
 */
class SourceAwareBlock extends Block
{
    public function kind(): string
    {
        return 'SourceAware';
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
        return ['source' => $ctx->source !== null ? (string) $ctx->source : null];
    }
}
