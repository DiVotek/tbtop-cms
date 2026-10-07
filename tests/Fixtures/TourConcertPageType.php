<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\Types\BasePageType;

/** A subtype whose base() names another subtype (ConcertPageType) instead of a top-level type. */
class TourConcertPageType extends BasePageType
{
    public function key(): string
    {
        return 'tour_concert';
    }

    #[\Override]
    public function base(): string
    {
        return 'concert';
    }
}
