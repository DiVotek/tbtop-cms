<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Types;

class ArticleType extends BasePageType
{
    public function key(): string
    {
        return 'article';
    }

    #[\Override]
    public function requiresParent(): bool
    {
        return true;
    }

    /** @return non-empty-list<string> */
    #[\Override]
    public function schemaTypes(): array
    {
        return ['Article', 'NewsArticle', 'BlogPosting'];
    }
}
