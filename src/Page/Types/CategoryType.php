<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Types;

class CategoryType extends BasePageType
{
    public function key(): string
    {
        return 'category';
    }

    /** @return list<string> */
    #[\Override]
    public function allowedChildren(): array
    {
        return ['category', 'article'];
    }

    #[\Override]
    public function maxDepth(): int
    {
        return 3;
    }

    /** @return non-empty-list<string> */
    #[\Override]
    public function schemaTypes(): array
    {
        return ['CollectionPage'];
    }
}
