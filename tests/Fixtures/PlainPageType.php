<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\Types\BasePageType;

/** Keyed 'plain' with only WebPage, for the og:type-is-website side of the schema-driven og:type test. */
class PlainPageType extends BasePageType
{
    public function key(): string
    {
        return 'plain';
    }

    /** @return non-empty-list<string> */
    #[\Override]
    public function schemaTypes(): array
    {
        return ['WebPage'];
    }
}
