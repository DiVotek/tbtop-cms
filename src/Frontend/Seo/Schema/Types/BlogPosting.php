<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema\Types;

class BlogPosting extends ArticleFamily
{
    use TranslatesSchemaLabel;

    public function key(): string
    {
        return 'BlogPosting';
    }
}
