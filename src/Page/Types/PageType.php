<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Types;

class PageType extends BasePageType
{
    public function key(): string
    {
        return 'page';
    }
}
