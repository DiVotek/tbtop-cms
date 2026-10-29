<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Collection\Collection;

/** A host collection: reviews tied to the services they are about. */
class ReviewsCollection extends Collection
{
    public function key(): string
    {
        return 'review';
    }

    public function label(): string
    {
        return 'Reviews';
    }

    public function fields(S $s): array
    {
        return [
            $s->text('author')->label('Author')->required(),
            $s->textarea('text')->label('Text')->translatable(),
            $s->number('rating')->label('Rating'),
            $s->pages('services')->label('Services'),
        ];
    }
}
