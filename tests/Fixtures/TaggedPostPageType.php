<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Page\Types\ArticleType;

/** An article subtype that picks tags and can create one from the select. */
class TaggedPostPageType extends ArticleType
{
    #[\Override]
    public function key(): string
    {
        return 'tagged_post';
    }

    #[\Override]
    public function base(): string
    {
        return 'article';
    }

    /** @return list<Field> */
    #[\Override]
    public function fields(S $s): array
    {
        return [$s->pages('tags')->of('category', 'tag')->creatable()->max(10)];
    }
}
