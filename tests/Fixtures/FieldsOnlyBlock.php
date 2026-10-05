<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;

/**
 * A block that is General-tab fields and nothing else: placeable nowhere, so
 * no picker offers it and it needs no component. Shares the `heading` key
 * with SimpleBlock for the key-collision test.
 */
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
