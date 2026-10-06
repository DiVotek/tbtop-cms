<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\Types\CategoryType;

/** A category subtype whose new articles are services by default. */
class ServicesCategoryType extends CategoryType
{
    #[\Override]
    public function key(): string
    {
        return 'services';
    }

    #[\Override]
    public function base(): string
    {
        return 'category';
    }

    #[\Override]
    public function childSubtype(): ?string
    {
        return 'service';
    }
}
