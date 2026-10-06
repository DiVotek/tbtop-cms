<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\Types\BasePageType;

/** An article subtype that publishes as an ItemPage, not an Article — its own schema list wins. */
class ServicePageType extends BasePageType
{
    public function key(): string
    {
        return 'service';
    }

    #[\Override]
    public function base(): string
    {
        return 'article';
    }

    /** @return non-empty-list<string> */
    #[\Override]
    public function schemaTypes(): array
    {
        return ['ItemPage'];
    }
}
