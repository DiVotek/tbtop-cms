<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\Schema\Types;

class ItemPage extends WebPageFamily
{
    use TranslatesSchemaLabel;

    public function key(): string
    {
        return 'ItemPage';
    }
}
