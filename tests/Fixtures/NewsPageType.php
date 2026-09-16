<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\Types\BasePageType;

/** Keyed 'news' rather than 'article', so og:type must come from the schema, not the page type key. */
class NewsPageType extends BasePageType
{
    public function key(): string
    {
        return 'news';
    }

    /** @return non-empty-list<string> */
    #[\Override]
    public function schemaTypes(): array
    {
        return ['Article'];
    }
}
