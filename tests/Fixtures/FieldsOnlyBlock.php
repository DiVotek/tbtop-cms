<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;

/** Placeable nowhere: no picker offers it and cms:blocks:types asks for no component. */
class FieldsOnlyBlock extends Block
{
    public function kind(): string
    {
        return 'FieldsOnly';
    }

    /** @return list<string> */
    #[\Override]
    public function placements(): array
    {
        return [];
    }

    /** @return list<Field> */
    public function form(S $s): array
    {
        return [$s->text('heading')->label('Heading')];
    }
}
