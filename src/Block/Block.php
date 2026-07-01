<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;

abstract class Block
{
    abstract public function kind(): string;

    /** @return list<Field> */
    abstract public function form(S $s): array;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function resolve(array $input, ResolveContext $ctx): array
    {
        return $input;
    }
}
