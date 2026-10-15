<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\Fixtures;

use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Types\BasePageType;

/** A content type whose template() gives a new page a Hero, then a FAQ the editor fills in. */
class LandingPageType extends BasePageType
{
    public function key(): string
    {
        return 'landing';
    }

    /** @return array<int, array<string, mixed>> */
    #[\Override]
    public function template(Page $page): array
    {
        return [
            ['id' => 'hero', 'kind' => 'Hero', 'data' => ['title' => ['en' => 'Welcome']]],
            ['kind' => 'Faq', 'active' => false],
        ];
    }
}
