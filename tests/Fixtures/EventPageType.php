<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Page\Types\BasePageType;

/** A host top-level type (base() is its own key) with fields of its own; ConcertPageType is its subtype. */
class EventPageType extends BasePageType
{
    public function key(): string
    {
        return 'event';
    }

    /** @return list<Field> */
    #[\Override]
    public function fields(S $s): array
    {
        return [
            $s->text('venue')->label('Venue')->translatable(),
            $s->number('capacity')->label('Capacity')->rules('integer'),
        ];
    }
}
