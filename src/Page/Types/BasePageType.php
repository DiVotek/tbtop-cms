<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Types;

use Tbtop\Cms\Page\Contracts\PageTypeInterface;

abstract class BasePageType implements PageTypeInterface
{
    public function label(): string
    {
        return ucfirst($this->key());
    }

    /** @return list<string> */
    public function allowedChildren(): array
    {
        return [];
    }

    public function requiresParent(): bool
    {
        return false;
    }

    public function maxDepth(): int
    {
        return 1;
    }
}
