<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Page\Types\BasePageType;

/** A subtype of EventPageType that re-declares the base's optional integer `capacity` as a required text. */
class BoxOfficePageType extends BasePageType
{
    public function key(): string
    {
        return 'box_office';
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
        return [$s->text('capacity')->label('Capacity')->required()];
    }
}
