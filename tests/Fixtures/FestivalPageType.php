<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Page\Types\BasePageType;

/** A second subtype of EventPageType, next to ConcertPageType, with a required field. */
class FestivalPageType extends BasePageType
{
    public function key(): string
    {
        return 'festival';
    }

    #[\Override]
    public function base(): string
    {
        return 'event';
    }

    /** @return list<Field> */
    #[\Override]
    public function fields(S $s): array
    {
        return [$s->text('headliner')->label('Headliner')->required()];
    }
}
