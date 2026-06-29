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
}
