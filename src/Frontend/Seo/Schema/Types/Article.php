<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema\Types;

class Article extends ArticleFamily
{
    use TranslatesSchemaLabel;

    public function key(): string
    {
        return 'Article';
    }
}
