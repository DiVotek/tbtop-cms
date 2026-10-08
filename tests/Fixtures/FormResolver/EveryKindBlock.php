<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures\FormResolver;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;

/** One field of every kind the form-driven resolve() has a rule for, and no resolve() of its own. */
class EveryKindBlock extends Block
{
    public function kind(): string
    {
        return 'EveryKind';
    }

    /** @return list<Node> */
    public function form(S $s): array
    {
        return [
            $s->text('title')->translatable()->required(),
            $s->textarea('note'),
            $s->richtext('body'),
            $s->media('image'),
            $s->media('gallery')->multiple(),
            $s->link('cta'),
            $s->number('count'),
            $s->slider('level'),
            $s->boolean('featured'),
            $s->checkbox('agreed'),
            $s->tags('keywords'),
            $s->checkboxlist('perks'),
            $s->select('sizes')->multiple(),
            $s->select('size'),
            $s->radio('tone'),
            $s->date('day'),
            $s->colorpicker('color'),
            $s->keyvalue('meta'),
            $s->pages('related'),
            $s->repeater('items')->fields([
                $s->text('label')->translatable(),
                $s->number('qty'),
            ]),
        ];
    }
}
