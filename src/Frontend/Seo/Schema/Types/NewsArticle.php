<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema\Types;

class NewsArticle extends ArticleFamily
{
    use TranslatesSchemaLabel;

    public function key(): string
    {
        return 'NewsArticle';
    }
}
