<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;

/** A block listing reviews, plus one featured review picked with single(). */
class ReviewsBlock extends Block
{
    public function kind(): string
    {
        return 'Reviews';
    }

    /** @return list<Field> */
    public function form(S $s): array
    {
        return [
            $s->text('heading')->label('Heading'),
            $s->collection('reviews')->of('review')->label('Reviews'),
            $s->collection('featured')->of('review')->single()->modes(['manual', 'latest'])->label('Featured'),
        ];
    }
}
