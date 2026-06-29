<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\Types\BasePageType;

class FaqPageType extends BasePageType
{
    public function key(): string
    {
        return 'faq';
    }
}
