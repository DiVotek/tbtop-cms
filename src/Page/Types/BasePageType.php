<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Types;

use Tbtop\Cms\Page\Contracts\PageTypeInterface;
use Tbtop\Cms\Page\Models\Page;

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

    /** Content-only by default; a source-backed type overrides this. */
    public function isSourceBacked(): bool
    {
        return false;
    }

    public function source(Page $page): ?object
    {
        return null;
    }

    /** @return array<int, array<string, mixed>>|null */
    public function template(Page $page): ?array
    {
        return null;
    }
}
