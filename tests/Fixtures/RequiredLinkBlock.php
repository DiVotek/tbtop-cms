<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;

class RequiredLinkBlock extends Block
{
    public function kind(): string
    {
        return 'RequiredLinkBlock';
    }

    /** @return list<Field> */
    public function form(S $s): array
    {
        return [$s->link('cta')->required()];
    }
}
