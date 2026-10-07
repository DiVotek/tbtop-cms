<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\Types\BasePageType;

/** A host container that is not a category and holds pages and articles. */
class SectionType extends BasePageType
{
    public function key(): string
    {
        return 'section';
    }

    /** @return list<string> */
    #[\Override]
    public function allowedChildren(): array
    {
        return ['page', 'article'];
    }
}
