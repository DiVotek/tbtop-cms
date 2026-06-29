<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Contracts;

interface PageTypeInterface
{
    public function key(): string;

    public function label(): string;

    /** @return list<string> */
    public function allowedChildren(): array;

    public function requiresParent(): bool;

    public function maxDepth(): int;
}
