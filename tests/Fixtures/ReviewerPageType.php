<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Page\Types\ArticleType;

/** A second article subtype with a `services` pages field of the same name, for the `from` filter. */
class ReviewerPageType extends ArticleType
{
    #[\Override]
    public function key(): string
    {
        return 'reviewer';
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
        return [
            $s->pages('services')->label('Services'),
        ];
    }
}
