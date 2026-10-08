<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures\FormResolver;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;

/** The field kinds whose stored value is an array rather than one scalar, their single siblings, a repeater in a repeater, and no resolve() of its own. */
class ArrayShapesBlock extends Block
{
    public function kind(): string
    {
        return 'ArrayShapes';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [
            $s->togglebuttons('days')->options([['value' => 'mon', 'label' => 'Mon'], ['value' => 'tue', 'label' => 'Tue']])->multiple(),
            $s->togglebuttons('day')->options([['value' => 'mon', 'label' => 'Mon'], ['value' => 'tue', 'label' => 'Tue']]),
            $s->daterange('period'),
            $s->upload('files')->multiple(),
            $s->upload('file'),
            $s->text('address')->set('fields', [$s->text('street')]),
            $s->repeater('sections')->fields([
                $s->text('heading')->translatable(),
                $s->repeater('rows')->fields([
                    $s->text('cell')->translatable(),
                    $s->number('span'),
                ]),
            ]),
        ];
    }
}
